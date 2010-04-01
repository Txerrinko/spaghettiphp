<?php

class PdoDatasource extends Datasource {
    protected $connection;
    protected $connected;
    protected $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    public function dsn() {
        return $this->config['dsn'];
    }
    public function connect($dsn = null, $username = null, $password = null) {
        if(!$this->connection):
            if(is_null($dsn)):
                $dsn = $this->dsn();
                $username = $this->config['user'];
                $password = $this->config['password'];
            endif;
            $this->connection = new PDO($dsn, $username, $password);
            $this->connected = true;
        endif;
        
        return $this->connection;
    }
    public function disconnect() {
        $this->connected = false;
        $this->connection = null;
        return true;
    }
    public function connection() {
        if(!$this->connected):
            $this->connect();
        endif;
        return $this->connection;
    }
    public function alias($fields) {
        if(is_array($fields)):
            if(is_hash($fields)):
                foreach($fields as $alias => $field):
                    if(!is_numeric($alias)):
                        $fields[$alias] = $field . ' AS ' . $alias;
                    endif;
                endforeach;
            endif;
            
            $fields = implode(',', $fields);
        endif;
        
        return $fields;
    }
    public function join($params) {
        $params += array(
            'type' => null,
            'on' => null
        );
        
        $join = 'JOIN ' . $this->alias($params['table']);
        
        if($params['type']):
            $join = strtoupper($params['type']) . ' ' . $join;
        endif;
        
        if($params['on']):
            $join .= ' ON ' . $params['on'];
        endif;
        
        return $join;
    }
    public function order($order) {
        if(is_array($order)):
            $order = implode(',', $order);
        endif;
        
        return $order;
    }
    public function query($sql = null) {
        $this->results = $this->connection()->query($sql);
        return $this->results;
    }
    public function fetch($sql) {
        return $this->connection->query($sql);
    }
}