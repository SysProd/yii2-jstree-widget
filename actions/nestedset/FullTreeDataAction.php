<?php

namespace SysProd\JsTreeWidget\actions\nestedset;

use Yii;
use yii\base\Action;
use yii\base\InvalidConfigException;
use yii\db\Query;
use yii\db\ActiveRecord;
use yii\web\Response;
use yii\caching\TagDependency;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\HtmlPurifier;

use SysProd\JsTreeWidget\widgets\TreeWidget;

/**
 * Class FullTreeDataAction
 *
 * @package SysProd\JsTreeWidget\actions\nestedset
 *
 * @property string $leftAttribute
 * @property string $rightAttribute
 * @property string $rootAttribute
 * @property string $className
 * @property string $modelLabelAttribute
 * @property string $tableName
 * @property string $selectedNodes
 */
class FullTreeDataAction extends Action
{
    /** @var string */
    public $leftAttribute = 'lft';
    /** @var string */
    public $rightAttribute = 'rgt';
    /** @var string set root column name for multi root tree */
    public $rootAttribute = false;
    /** @var  ActiveRecord */
    public $className;
    /** @var string */
    public $modelLabelAttribute = 'name';
    /** @var  string */
    private $tableName;

    private $selectedNodes = [];
    /**
     * @inheritdoc
     */
    public function init()
    {
        if (true === empty($this->className) || false === is_subclass_of($this->className, ActiveRecord::class)) {
            throw new InvalidConfigException('"className" param must be set and must be child of ActiveRecord');
        }
        /** @var ActiveRecord $class */
        $class = $this->className;
        $this->tableName = $class::tableName();
        $scheme = Yii::$app->getDb()->getTableSchema($this->tableName);
        $columns = $scheme->columns;
        if (false !== $this->rootAttribute && false === isset($columns[$this->rootAttribute])) {
            throw new InvalidConfigException("Column '{$this->rootAttribute}' not found in the '{$this->tableName}' table");
        }
        if (false === isset(
                $columns[$this->leftAttribute],
                $columns[$this->rightAttribute],
                $columns[$this->modelLabelAttribute]
            )
        ) {
            throw new InvalidConfigException(
                "Some of the '{$this->leftAttribute}', '{$this->rightAttribute}', '{$this->modelLabelAttribute}', "
                . "not found in the '{$this->tableName}' columns list"
            );
        }
        TreeWidget::registerTranslations();
        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        $selectArray = [
            'id',
            $this->leftAttribute,
            $this->rightAttribute,
            $this->modelLabelAttribute,
        ];
        $orderBy = [];
        if (false !== $this->rootAttribute) {
            $selectArray[] = $this->rootAttribute;
            $orderBy[$this->rootAttribute] = SORT_ASC;
        }
        $orderBy[$this->leftAttribute] = SORT_ASC;
        Yii::$app->response->format = Response::FORMAT_JSON;
        $data = (new Query())
            ->from($this->tableName)
            ->select($selectArray)
            ->orderBy($orderBy)
            ->all();

        $this->selectedNodes = explode(',', Yii::$app->request->get('selected', ''));

        return $this->prepareNestedData($data);
    }

    /**
     * Converts single or multi root Nested Set database data into multidimensional array for using in the
     * jsTree widget
     *
     * @param array $data
     * @param int $lft
     * @param null $rgt
     * @param int $root
     * @return array
     */
    public function prepareNestedData($data, $lft = 0, $rgt = null, $root = 0)
    {
        $res = [];
        foreach ($data as $row) {
            $currentRoot = isset($row[$this->rootAttribute]) ? $row[$this->rootAttribute] : 0;
            if (is_null($rgt) || $row[$this->rightAttribute] < $rgt && $root == $currentRoot) {
                // Protection against xss
//                $name = $row[$this->modelLabelAttribute];
                $name = ArrayHelper::getValue($row, $this->modelLabelAttribute, 'item');
                if(Html::encode($name) !== $name){ $name = HtmlPurifier::process($name); }

                if ($lft + 1 == $row[$this->leftAttribute]) {
                    if ($row[$this->leftAttribute] + 1 !== $row[$this->rightAttribute]) {
                        $res[] = [
                            'id' => (int)$row['id'],
                            'text' => Html::encode($name),
                            'a_attr' => [
                                'data-id' => (int)$row['id'],
                            ],
                            'children' => self::prepareNestedData(
                                $data,
                                $row[$this->leftAttribute],
                                $row[$this->rightAttribute],
                                $currentRoot
                            ),
                            'state' => [
                                'selected' => in_array($row['id'], $this->selectedNodes),
                            ],
                        ];
                    } else {
                        $res[] = [
                            'id' => (int)$row['id'],
                            'text' => Html::encode($name),
                            'a_attr' => [
                                'data-id' => (int)$row['id'],
                            ],
                            'children' => [],
                            'state' => [
                                'selected' => in_array($row['id'], $this->selectedNodes),
                            ],
                        ];
                    }
                    $lft = $row[$this->rightAttribute];
                } else if ($row[$this->leftAttribute] == 1 && $root !== $currentRoot) {
                    $res[] = [
                        'id' => (int)$row['id'],
                        'text' => Html::encode($name),
                        'a_attr' => [
                            'data-id' => (int)$row['id'],
                        ],
                        'children' => self::prepareNestedData(
                            $data,
                            $row[$this->leftAttribute],
                            $row[$this->rightAttribute],
                            $currentRoot
                        ),
                        'state' => [
                            'selected' => in_array($row['id'], $this->selectedNodes),
                        ],
                    ];
                }
            }
        }
        return $res;
    }
}