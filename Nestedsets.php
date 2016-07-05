<?php

/**
 * created by A-lex aka quadrogod
 */

namespace Techcon\Helpers;

use Illuminate\Support\Facades\DB;

class Nestedsets {

    private $_tableName = NULL;
    /**
     *  Если в одной таблице несколько деревьев с фильтром (например для каждого языка своё дерево)
     * то сюда передается массив key=>value
     * @var array
     */
    private $_filter = array();

    protected $_cLeftKey = '_lft';
    protected $_cRightKey = '_rgt';
    protected $_cLevel = 'level';
    protected $_cParent = 'parent_id';

    protected $_primaryKey = 'id';

    protected $_parentItem = FALSE;
    protected $_currentItem = FALSE;
    protected $_dbl_count = 0;
    protected $_where = '';

    public function __construct($tableName, $filter) {
        //@todo: можно еще добавить эксепшн если таблица не указана или проверку на существование таблицы
        $this->_tableName = $tableName;
        $this->_filter = $filter;

    }

    /**
     * Создает экземпляр класса для работы с таблицей $table_name
     * @param string $table_name
     * @return \NESTEDSETS
     */
    public static function factory ($tableName, $filter=[]){
        return new Nestedsets($tableName, $filter);
    }

    /**
     * Получает элементы текущей записи и родительской для перемещения в дереве
     * @param int $current_id
     * @param int $parent_id
     * @return boolean|\NESTEDSETS
     */
    public function move($current_id, $parent_id)
    {
        $this->_parentItem = (array) \DB::table( $this->_tableName )
            ->where('id', '=', $parent_id)
            ->first([$this->_primaryKey, $this->_cLeftKey, $this->_cRightKey, $this->_cParent, $this->_cLevel]);

        $this->selectCurrent($current_id);

        return $this;
    }

    private function selectCurrent($current_id)
    {
        $this->_currentItem = (array) \DB::table( $this->_tableName )
            ->where('id', '=', $current_id)
            ->first([$this->_primaryKey, $this->_cLeftKey, $this->_cRightKey, $this->_cParent, $this->_cLevel]);

        // если нет текущей записи
        if (!$this->_currentItem)
            return FALSE;

        return $this;
    }


    /**
     * Подготавливает таблицу к перемещению узлов
     */
    private function prepare()
    {
        \DB::beginTransaction(); // запускаем транзакцию, в случае ошибки при тестировании после перемещения будет произведен откат изменений

        $this->_dbl_count = $this->_currentItem[$this->_cRightKey] - $this->_currentItem[$this->_cLeftKey] + 1;

        $query = "
            UPDATE ".$this->_tableName."
            SET level = (0 - ".$this->_cLevel."),
                ".$this->_cLeftKey." = (".$this->_cLeftKey." - ".$this->_currentItem[$this->_cLeftKey]." + 1),
                ".$this->_cRightKey." = (".$this->_cRightKey." - ".$this->_currentItem[$this->_cLeftKey]." + 1)
            WHERE ".$this->_cLeftKey." >= ".$this->_currentItem[$this->_cLeftKey]."
                AND ".$this->_cRightKey." <= ".$this->_currentItem[$this->_cRightKey].
                $this->_where
        ;
        \DB::update($query);

        $query = "
            UPDATE ".$this->_tableName."
            SET ".$this->_cLeftKey." = CASE WHEN ".$this->_cLeftKey." > ".$this->_currentItem[$this->_cLeftKey]."
                                THEN ".$this->_cLeftKey." - ".$this->_dbl_count."
                                ELSE ".$this->_cLeftKey." END,
                ".$this->_cRightKey." = ".$this->_cRightKey." - ".$this->_dbl_count."
            WHERE ".$this->_cRightKey." > ".$this->_currentItem[$this->_cRightKey]."
                AND ".$this->_cLevel." > 0".
                $this->_where
        ;
        DB::update($query);

        if ($this->_parentItem && is_array($this->_parentItem))
        {
            $this->_parentItem = (array) \DB::table( $this->_tableName )->where($this->_primaryKey, '=', $this->_parentItem[$this->_primaryKey])->first([$this->_primaryKey, $this->_cLeftKey, $this->_cRightKey, $this->_cParent, $this->_cLevel]);
        }
        else
        {
            $right_key = collect(\DB::select( "SELECT IFNULL(MAX(".$this->_cRightKey."),0) as ".$this->_cRightKey." FROM ".$this->_tableName." WHERE ".$this->_tableName.".".$this->_cLevel." >0 ".$this->_where ))->first();
            $right_key = ($right_key && isset($right_key->{$this->_cRightKey})) ? $right_key->{$this->_cRightKey} + 1 : 1;

            $this->_parentItem = [
                $this->_primaryKey  =>  0,
                $this->_cRightKey   =>  $right_key,
                $this->_cLevel      =>  0
            ];
        }
    }


    /**
     * Вставляет элемент как последний потомок выбранного родительского элемента
     * @return boolean
     */
    public function asChild()
    {
        $this->prepare();

        if ($this->_parentItem[$this->_primaryKey]>0) {
            $query = "
                    UPDATE ".$this->_tableName
                    ." SET ".$this->_cRightKey." = ".$this->_cRightKey." + ".$this->_dbl_count.",
                            ".$this->_cLeftKey." = CASE WHEN ".$this->_cLeftKey." > ".$this->_parentItem[$this->_cRightKey]."
                                                            THEN ".$this->_cLeftKey." + ".$this->_dbl_count."
                                                            ELSE ".$this->_cLeftKey." END
                    WHERE ".$this->_cRightKey." >= ".$this->_parentItem[$this->_cRightKey]
                        ." AND ".$this->_cLevel." > 0 ".
                    $this->_where
            ;
            \DB::update($query);
        }

        //имитация создания нового узла
        $shift = $this->_parentItem[$this->_cRightKey] - 1;
        $level = $this->_parentItem[$this->_cLevel] + 1 - $this->_currentItem[$this->_cLevel];
        $query = "
                UPDATE ".$this->_tableName."
                SET ".$this->_cLevel." = (0 - ".$this->_cLevel." + ".$level."),
                        ".$this->_cLeftKey." = (".$this->_cLeftKey." + ".$shift."),
                        ".$this->_cRightKey." = (".$this->_cRightKey." + ".$shift.")
                WHERE ".$this->_cLevel." < 0".
            $this->_where
        ;
        \DB::update($query);

        //обновление родителя
        $query = "
                UPDATE ".$this->_tableName."
                SET ".$this->_cParent." = ".$this->_parentItem[$this->_primaryKey]."
                WHERE ".$this->_primaryKey." = ".$this->_currentItem[$this->_primaryKey].
            $this->_where
        ;
        \DB::update($query);

        return $this->testing();
    }

    /**
     * Вставляет текущий элемент перед родительским (требуемым)
     * @return boolean
     */
    public function before()
    {
        if (!$this->_parentItem || !is_array($this->_parentItem))
            return FALSE;

        $this->prepare();

        $query = "
                UPDATE ".$this->_tableName."
                SET ".$this->_cRightKey." = ".$this->_cRightKey." + ".$this->_dbl_count.",
                        ".$this->_cLeftKey." = CASE WHEN ".$this->_cLeftKey." >= ".$this->_parentItem[$this->_cLeftKey]."
                                                THEN ".$this->_cLeftKey." + ".$this->_dbl_count."
                                                ELSE ".$this->_cLeftKey." END
                WHERE ".$this->_cRightKey." > ".$this->_parentItem[$this->_cLeftKey]."
                    AND ".$this->_cLevel." > 0 ".
                $this->_where
        ;
        \DB::update($query);

        //имитация создания нового узла
        $shift = $this->_parentItem[$this->_cLeftKey] - 1;
        $level = $this->_parentItem[$this->_cLevel] - $this->_currentItem[$this->_cLevel];
        $query = "
                UPDATE ".$this->_tableName."
                SET ".$this->_cLevel." = (0 - ".$this->_cLevel." + ".$level."),
                        ".$this->_cLeftKey." = (".$this->_cLeftKey." + ".$shift."),
                        ".$this->_cRightKey." = (".$this->_cRightKey." + ".$shift.")
                WHERE ".$this->_cLevel." < 0".
            $this->_where
        ;

        \DB::update($query);

        //обновление родителя
        $query = "
                UPDATE ".$this->_tableName."
                SET ".$this->_cParent." = ".$this->_parentItem[$this->_cParent]."
                WHERE ".$this->_primaryKey." = ".$this->_currentItem[$this->_primaryKey].
            $this->_where
        ;

        \DB::update($query);

        return $this->testing();
    }


    /**
     * Тестирует таблицу на наличие ошибочных значений LEFT и RIGHT ключей
     * @return boolean
     */
    private function testing ()
    {
        $query = "
            SELECT t1.*,t2.*
            FROM ".$this->_tableName." AS t1, ".$this->_tableName." AS t2
            WHERE (t1.".$this->_cLeftKey." = t2.".$this->_cLeftKey." OR t1.".$this->_cRightKey." = t2.".$this->_cRightKey.")
                AND t1.".$this->_primaryKey." != t2.".$this->_primaryKey." ".
                $this->_where
        ;

        $rows = \DB::select($query);

        if ((count($rows)))
            \DB::rollback(); // если есть ошибки откатываемся
        else
            \DB::commit();   // иначе подтверждаем транзакцию и все изменения

        return (count($rows)) ? false : true;
    }


    /**
     * Удаляет ветку. По умолчанию включая все дочерние элементы.
     * @param int $item_id
     * @param boolean $andChilds
     */
    public function deleteNode($item_id, $andChilds = true)
    {
        $this->selectCurrent($item_id);
        if($andChilds)
        {   // Удаляем весь узел (ветку) включая потомков:
            $query = "DELETE FROM ".$this->_tableName." WHERE ".$this->_cRightKey." >= ".$this->_currentItem[$this->_cLeftKey]." AND ".$this->_cRightKey." <= ".$this->_currentItem[$this->_cRightKey]." ".$this->_where;
            \DB::delete($query);

            //Обновление родительской ветки
            $query = "UPDATE ".$this->_tableName." SET ".$this->_cLeftKey." = IF(".$this->_cLeftKey." > ".$this->_currentItem[$this->_cLeftKey].", ".$this->_cLeftKey." - (".$this->_currentItem[$this->_cRightKey]." - ".$this->_currentItem[$this->_cLeftKey]." + 1), ".$this->_cLeftKey."), ".$this->_cRightKey." = ".$this->_cRightKey." - (".$this->_currentItem[$this->_cRightKey]." - ".$this->_currentItem[$this->_cLeftKey]." + 1) WHERE ".$this->_cRightKey." > ".$this->_currentItem[$this->_cRightKey]." ".$this->_where;
            \DB::update($query);
        }
        else
        {
            // По умолчанию удаляется узел (ветка) оставляя потомков и перекидывая структуру на один уровень выше:
        }
        return $this->testing();
    }

    /**
     * Восстанавливает PARENT и LEVEL колонку по ключам LEFT_KEY и RIGHT_KEY
     * @return \NESTEDSETS
     */
    public function repairByLRKeys()
    {
        $this->repairParentByLRKeys()->repairLevelByParent();
        return $this;
    }


    /**
     * Восстанавливает PARENT колонку по ключам LEFT_KEY и RIGHT_KEY
     * @return \NESTEDSETS
     */
    public function repairParentByLRKeys(){
        $query = "  SELECT A.id, IF(B.id IS NULL, 0, B.id) AS `".$this->_cParent."`
                    FROM ".$this->_tableName." AS A
                    LEFT OUTER JOIN ".$this->_tableName." AS B ON B.`".$this->_cLeftKey."` = (SELECT MAX(C.`".$this->_cLeftKey."`) FROM ".$this->_tableName." AS C WHERE A.`".$this->_cLeftKey."` > C.`".$this->_cLeftKey."` AND A.`".$this->_cLeftKey."` < C.`".$this->_cRightKey."`)";
        $items = \DB::select($query);

        foreach ($items as $k=>$v)
        {
            \DB::table($this->_tableName)
                ->where($this->_primaryKey, '=', $v->{$this->_primaryKey})
                ->update([$this->_cParent => $v->{$this->_cParent}]);
        }

        return $this;
    }


    /**
     * Восстанавливает значение колонки LEVEL (уровень вложенности)
     * по колонке PARENT (родительский элементы)
     * @return \NESTEDSETS
     */
    public function repairLevelByParent()
    {
        $items = \DB::table($this->_tableName)
                ->select($this->_primaryKey, $this->_cLeftKey, $this->_cRightKey, $this->_cParent, $this->_cLevel)
                ->orderBy($this->_cLeftKey, 'asc')
                ->get();

        $values = [];
        foreach ($items as $k=>$item){
            $values[$item->{$this->_primaryKey}] = $item;
            if($values[$item->{$this->_primaryKey}]->{$this->_cParent} == $values[$item->{$this->_primaryKey}]->{$this->_primaryKey})
            {
                $values[$item->{$this->_primaryKey}]->{$this->_cLevel} = 1;
            }
            else
            {
                $parent_key = $values[$item->{$this->_primaryKey}]->{$this->_cParent};
                if(isset($values[$parent_key]->{$this->_cLevel}))
                    $values[$item->{$this->_primaryKey}]->{$this->_cLevel} = $values[$parent_key]->{$this->_cLevel} + 1;
                else
                    $values[$item->{$this->_primaryKey}]->{$this->_cLevel} = 1;
            }
            \DB::table($this->_tableName)
                ->where($this->_primaryKey, '=', $item->{$this->_primaryKey})
                ->update($values[$item->{$this->_primaryKey}]);
        }

        return $this;
    }


}
