<?php
/**
 *  Put description here
 *
 *  Licensed under The MIT License.
 *  Redistributions of files must retain the above copyright notice.
 *  
 *  @package Spaghetti
 *  @subpackage Spaghetti.Core.Model
 *  @license http://www.opensource.org/licenses/mit-license.php The MIT License
 */

class Model extends Object {
    /**
     * Associações entre modelos disponíveis
     */
    public $associations = array("hasMany", "belongsTo", "hasOne");
    /**
     * Chaves disponíveis para cada associação
     */
    public $associationKeys = array(
        "hasMany" => array("className", "foreignKey", "conditions", "order", "limit", "dependent"),
        "belongsTo" => array("className", "foreignKey", "conditions"),
        "hasOne" => array("className", "foreignKey", "conditions", "dependent")
    );
    /**
     * Associações do tipo Belongs To
     */
    public $belongsTo = array();
    /**
     * Associações do tipo Has Many
     */
    public $hasMany = array();
    /**
     * Associações do tipo Has One
     */
    public $hasOne = array();
    /**
     * Dados do registro
     */
    public $data = array();
    /**
     * ID do registro
     */
    public $id = null;
    /**
     * Nível de recursão padrão das consultas find
     */
    public $recursion = 1;
    /**
     * Descrição da tabela do modelo
     */
    public $schema = array();
    /**
     * Nome da tabela usada pelo modelo
     */
    public $table = null;
    /**
     * ID do último registro inserido
     */
    public $insertId = null;
    /**
     * Registros afetados pela consulta
     */
    public $affectedRows = null;
    
    public function __construct($table = null) {
        if($this->table === null):
            if($table !== null):
                $this->table = $table;
            else:
                $database = Config::read("database");
                $this->table = $database["prefix"] . Inflector::underscore(get_class($this));
            endif;
        endif;
        if($this->table !== false):
            $this->describeTable();
        endif;
        ClassRegistry::addObject(get_class($this), $this);
        $this->createLinks();
    }
    public function __call($method, $params) {
        $params = array_merge($params, array(null, null, null, null, null));
        if(preg_match("/findAllBy(.*)/", $method, $field)):
            $field[1] = Inflector::underscore($field[1]);
            return $this->findAllBy($field[1], $params[0], $params[1], $params[2], $params[3], $params[4]);
        elseif(preg_match("/findBy(.*)/", $method, $field)):
            $field[1] = Inflector::underscore($field[1]);
            return $this->findBy($field[1], $params[0], $params[1], $params[2], $params[3]);
        endif;
    }
    public function __set($field, $value = "") {
        if(isset($this->schema[$field])):
            $this->data[$field] = $value;
        elseif(is_subclass_of($value, "Model")):
            $this->{$field} = $value;
        endif;
    }
    public function __get($field) {
        if(isset($this->schema[$field])):
            return $this->data[$field];
        endif;
        return null;
    }
    public function &getConnection() {
        static $instance = array();
        if(!isset($instance[0]) || !$instance[0]):
            $instance[0] =& Model::connect();
        endif;
        return $instance[0];
    }
    public function connect() {
        $config = Config::read("database");
        $link = mysql_connect($config["host"], $config["user"], $config["password"]);
        mysql_selectdb($config["database"], $link);
        return $link;
    }
    public function beforeSave() {
	return true;
    }
    public function afterSave() {
	return true;
    }
    public function describeTable() {
        $tableSchema = $this->fetchResults($this->sqlQuery("describe"));
        $modelSchema = array();
        foreach($tableSchema as $field):
            preg_match("/([a-z]*)\(?([0-9]*)?\)?/", $field["Type"], $type);
            $modelSchema[$field["Field"]] = array(
                "type" => $type[1],
                "length" => $type[2],
                "null" => $field["Null"] == "YES" ? true : false,
                "default" => $field["Default"],
                "key" => $field["Key"],
                "extra" => $field["Extra"]
            );
        endforeach;
        return $this->schema = $modelSchema;
    }
    // método importado da versao 0.2 do Spaghetti*
    public function createLinks() {
        foreach($this->associations as $type):
            $associations =& $this->{$type};
            foreach($associations as $key => $assoc):
                if(is_numeric($key)):
                    $key = array_unset($associations, $key);
                    if(is_array($assoc)):
                        $associations[$key["className"]] = $key;
                    else:
                        $associations[$key] = array("className" => $key);
                    endif;
                elseif(!isset($assoc["className"])):
                    $associations[$key]["className"] = $key;
                endif;
                $className = $associations[$key]["className"];
                if(!isset($this->{$className})):
                    if($class =& ClassRegistry::load($className)):
                        $this->{$className} = $class;
                    else:
                        $this->error("missingModel", array("model" => $className));
                    endif;
                endif;
                $this->generateAssociation($type);
            endforeach;
        endforeach;
    }
    // método importado da versao 0.2 do Spaghetti*
    public function generateAssociation($type) {
        $associations =& $this->{$type};
        foreach($associations as $k => $assoc):
            foreach($this->associationKeys[$type] as $key):
                if(!isset($assoc[$key])):
                    $data = null;
                    switch($key):
                        case "foreignKey":
                            $class = $assoc["className"];
                            $data = ($type == "belongsTo") ? Inflector::underscore($class . "Id") : Inflector::underscore(get_class($this) . "Id");
                            break;
                        case "conditions":
                            $data = array();
                    endswitch;
                    $associations[$k][$key] = $data;
                endif;
            endforeach;
        endforeach;
        return true;
    }
    public function sqlQuery($type = "select", $parameters = array(), $values = array(), $order = null, $limit = null, $flags = null, $fields = array()) {
        $params = $this->sqlConditions($parameters);
        $values = $this->sqlConditions($values);
        $fields = empty($fields) ? "*" : join(",", $fields);
        if(is_array($order)):
            $orders = "";
            foreach($order as $key => $value):
                if(!is_numeric($key)):
                    $value = "{$key} {$value}";
                endif;
                $orders .= "{$value},";
            endforeach;
            $order = trim($orders, ",");
        endif;
        if(is_array($flags)):
            $flags = join(" ", $flags);
        endif;
        $types = array(
            "delete" => "DELETE" . if_string($flags, " {$flags}") . " FROM {$this->table}" . if_string($params, " WHERE {$params}") . if_string($order, " ORDER BY {$order}") . if_string($limit, " LIMIT {$limit}"),
            "insert" => "INSERT" . if_string($flags, " {$flags}") . " INTO {$this->table} SET " . $this->sqlSet($params),
            "replace" => "REPLACE" . if_string($flags, " {$flags}") . " INTO {$this->table}" . if_string($params, " SET {$params}"),
            "select" => "SELECT" . if_string($flags, " {$flags}") . " {$fields} FROM {$this->table}" . if_string($params, " WHERE {$params}") . if_string($order, " ORDER BY {$order}") . if_string($limit, " LIMIT {$limit}"),
            "truncate" => "TRUNCATE TABLE {$this->table}",
            "update" => "UPDATE" . if_string($flags, " {$flags}") . " {$this->table} SET " . $this->sqlSet($values) . if_string($params, " WHERE {$params}") . if_string($order, " ORDER BY {$order}") . if_string($limit, " LIMIT {$limit}"),
            "describe" => "DESCRIBE {$this->table}"
        );
        return $types[$type];
    }
    public function sqlSet($data = "") {
        return preg_replace("/' AND /", "', ", $data);
    }
    public function sqlConditions($conditions) {
        $sql = "";
        $logic = array("or", "or not", "||", "xor", "and", "and not", "&&", "not");
        $comparison = array("=", "<>", "!=", "<=", "<", ">=", ">", "<=>", "LIKE");
        if(is_array($conditions)):
            foreach($conditions as $field => $value):
                if(is_string($value) && is_numeric($field)):
                    $sql .= "{$value} AND ";
                elseif(is_array($value)):
                    if(is_numeric($field)):
                        $field = "OR";
                    elseif(in_array($field, $logic)):
                        $field = strtoupper($field);
                    elseif(preg_match("/([a-z]*) BETWEEN/", $field, $parts) && $this->schema[$parts[1]]):
                        $sql .= "{$field} '" . join("' AND '", $value) . "'";
                        continue;
                    else:
                        $values = array();
                        foreach($value as $item):
                            $values []= $this->sqlConditions(array($field => $item));
                        endforeach;
                        $sql .= "(" . join(" OR ", $values) . ") AND ";
                        continue;
                    endif;
                    $sql .= preg_replace("/' AND /", "' {$field} ", $this->sqlConditions($value));
                else:
                    if(preg_match("/([a-z]*) (" . join("|", $comparison) . ")/", $field, $parts) && $this->schema[$parts[1]]):
                        if(is_null($value)):
                            $sql .= "{$parts[1]} {$parts[2]} NULL AND ";
                        else:
                            $value = $this->escape($value);
                            $sql .= "{$parts[1]} {$parts[2]} '{$value}' AND ";
                        endif;
                    elseif($this->schema[$field]):
                        if(is_null($value)):
                            $sql .= "{$field} = NULL AND ";
                        else:
                            $value = $this->escape($value);
                            $sql .= "{$field} = '{$value}' AND ";
                        endif;
                    endif;
                endif;
            endforeach;
            $sql = trim($sql, " AND ");
        else:
            $sql = $conditions;
        endif;
        return $sql;
    }
    public function execute($query) {
        return mysql_query($query, Model::getConnection());
    }
    public function fetchResults($query) {
        $results = array();
        if($query = $this->execute($query)):
            while($row = mysql_fetch_assoc($query)):
                $results []= $row;
            endwhile;
        endif;
        return $results;
    }
    public function findAll($conditions = array(), $order = null, $limit = null, $recursion = null, $fields = array()) {
        $recursion = pick($recursion, $this->recursion);
        $results = $this->fetchResults($this->sqlQuery("select", $conditions, null, $order, $limit, null, $fields));
        if($recursion >= 0):
            foreach($this->associations as $type):
                if($recursion != 0 || ($type != "hasMany" && $type != "hasOne")):
                    foreach($this->{$type} as $name => $assoc):
                        foreach($results as $key => $result):
                            if(isset($this->{$assoc["className"]}->schema[$assoc["foreignKey"]])):
                                $assocCondition = array($assoc["foreignKey"] => $result["id"]);
                            else:
                                $assocCondition = array("id" => $result[$assoc["foreignKey"]]);
                            endif;
                            $attrCondition = isset($conditions[Inflector::underscore($assoc["className"])]) ? $conditions[Inflector::underscore($assoc["className"])] : array();
                            $condition = array_merge($attrCondition, $assoc["conditions"], $assocCondition);
                            $assocRecursion = $type != "belongsTo" ? $recursion - 2 : $recursion - 1;
                            $rows = $this->{$assoc["className"]}->findAll($condition, null, null, $assocRecursion);
                            $results[$key][Inflector::underscore($name)] = $type == "hasMany" ? $rows : $rows[0];
                        endforeach;
                    endforeach;
                endif;
            endforeach;
        endif;
        return $results;
    }
    public function findAllBy($field = "id", $value = null, $conditions = array(), $order = null, $limit = null, $recursion = null) {
        if(!is_array($conditions)) $conditions = array();
        $conditions = array_merge(array($field => $value), $conditions);
        return $this->findAll($conditions, $order, $limit, $recursion);
    }
    public function find($conditions = array(), $order = null, $recursion = null, $fields = array()) {
        $results = $this->findAll($conditions, $order, 1, $recursion, $fields);
        return $results[0];
    }
    public function findBy($field = "id", $value = null, $conditions = array(), $order = null, $recursion = null) {
        if(!is_array($conditions)) $conditions = array();
        $conditions = array_merge(array($field => $value), $conditions);
        return $this->find($conditions, $order, $recursion);
    }
    public function all($params = array()) {
        $params = array_merge(
            array("conditions" => array(), "order" => null, "recursion" => null, "limit" => null, "fields" => array()),
            $params
        );
        return $this->findAll($params["conditions"], $params["order"], $params["limit"], $params["recursion"], $params["fields"]);
    }
    public function first($params = array()) {
        $params = array_merge(
            array("conditions" => array(), "order" => null, "recursion" => null, "fields" => array()),
            $params
        );
        return $this->find($params["conditions"], $params["order"], $params["recursion"], $params["fields"]);
    }
    public function create() {
        $this->id = null;
        $this->data = array();
    }
    public function read($id = null, $recursion = null) {
        if($id != null):
            $this->id = $id;
        endif;
        $this->data = $this->find(array("id" => $this->id), null, $recursion);
        return $this->data;
    }
    public function update($conditions = array(), $data = array()) {
        if($this->execute($this->sqlQuery("update", $conditions, $data))):
            $this->affectedRows = mysql_affected_rows();
            return true;
        endif;
        return false;
    }
    public function insert($data = array()) {
        if($this->execute($this->sqlQuery("insert", $data))):
            $this->insertId = mysql_insert_id();
            $this->affectedRows = mysql_affected_rows();
            return true;
        endif;
        return false;
    }
    public function save($data = array()) {
        if(empty($data)):
            $data = $this->data;
        endif;
        $date = date("Y-m-d H:i:s");
        if(isset($this->schema["modified"]) && !isset($data["modified"]) && in_array($this->schema["modified"]["type"], array("date", "datetime", "time"))):
            $data["modified"] = $date;
        endif;
        
        $saveData = array();
        foreach($data as $field => $value):
            if(isset($this->schema[$field])):
                $saveData[$field] = $value;
            endif;
        endforeach;
        
		$this->beforeSave();
        if(isset($saveData["id"]) && $this->exists($saveData["id"])):
            $this->update(array("id" => $saveData["id"]), $saveData);
            $this->id = $saveData["id"];
        else:
            if(isset($this->schema["created"]) && !isset($saveData["created"]) && in_array($this->schema["modified"]["type"], array("date", "datetime", "time"))):
                $saveData["created"] = $date;
            endif;
            $this->insert($saveData);
            $this->id = $this->getInsertId();
        endif;
        $this->afterSave();
        
        foreach(array("hasOne", "hasMany") as $type):
            foreach($this->{$type} as $class => $assoc):
                $assocModel = Inflector::underscore($class);
                if(isset($data[$assocModel])):
                    $data[$assocModel][$assoc["foreignKey"]] = $this->id;
                    $this->{$class}->save($data);
                endif;
            endforeach;
        endforeach;
        
        return $this->data = $this->read($this->id);
    }
    public function saveAll($data) {
        if(isset($data[0]) && is_array($data[0])):
            foreach($data as $row):
                $this->save($row);
            endforeach;
        else:
            return $this->save($data);
        endif;
        return true;
    }
    public function exists($id = null) {
        $row = $this->findById($id);
        if(!empty($row)):
            return true;
        endif;
        return false;
    }
    public function deleteAll($conditions = array(), $order = null, $limit = null) {
        if($this->execute($this->sqlQuery("delete", $conditions, null, $order, $limit))):
            $this->affectedRows = mysql_affected_rows();
            return true;
        endif;
        return false;
    }
    /**
     * O método Model::delete() exclui um registro da tabela do modelo, de acordo com
     * o ID passado como parâmetro, e seus dependentes em associações de modelos, se
     * houverem.
     * 
     * @return
     */
    public function delete($id = null, $dependent = false) {
        $return = $this->deleteAll(array("id" => $id), null, 1);
        if($dependent):
            foreach(array("hasMany", "hasOne") as $type):
                foreach($this->{$type} as $model => $assoc):
                    if($assoc["dependent"]):
                        $this->{$model}->deleteAll(array(
                            $assoc["foreignKey"] => $id
                        ));
                    endif;
                endforeach;
            endforeach;
        endif;
        return $return;
    }
    /**
     * O método Model::get_insert_id() retorna o ID do último registro inserido
     * na tabela do modelo.
     *
     * @return integer
     */
    public function getInsertId() {
        return $this->insertId;
    }
    /**
     * O método Model::get_affected_rows() retorna o número de registros afetados
     * por uma consulta.
     */
    public function getAffectedRows() {
        return $this->affectedRows;
    }
    /**
     * O método Model::escape() prepara dados para uso em consultas SQL, retirando
     * caracteres que possam ser perigosos, evitando possíveis ataques de SQL Injection.
     */
    public function escape($data) {
        if(get_magic_quotes_gpc()):
            $data = stripslashes($data);
        endif;
        return mysql_real_escape_string($data, Model::getConnection());
    }
}
?>