<?php

// Loads Library/config.json, caching the decoded array as a compiled PHP file
// (Library/config.cache.php) so opcache can serve it as bytecode instead of
// re-parsing ~183KB of JSON on every single request. Regenerated automatically
// whenever config.json's mtime changes.
function lindsey_load_config($config_path) {
    $cache_path = dirname($config_path) . '/config.cache.php';

    if (file_exists($cache_path) && filemtime($cache_path) >= filemtime($config_path)) {
        return include $cache_path;
    }

    $config = json_decode(file_get_contents($config_path), true);
    file_put_contents($cache_path, '<?php return ' . var_export($config, true) . ';' . PHP_EOL);

    return $config;
}

// Holds the model class map for one object_title (e.g. $CENSUS) and only
// instantiates a model's engine class the first time it's actually accessed,
// instead of Bootloader.php eagerly constructing every declared model on
// every request regardless of which endpoint is being served.
class LindseyLazyContainer {
    private $config;
    private $model_class_map;
    private $instances = [];

    public function __construct($config, $model_class_map) {
        $this->config = $config;
        $this->model_class_map = $model_class_map;
    }

    public function __get($name) {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (!isset($this->model_class_map[$name])) {
            return null;
        }

        $class_name = $this->model_class_map[$name];
        $this->instances[$name] = new $class_name($this->config);

        return $this->instances[$name];
    }

    public function __isset($name) {
        return isset($this->model_class_map[$name]);
    }
}

?>
