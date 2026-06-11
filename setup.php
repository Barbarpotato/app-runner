<?php

// CLI handling
if (php_sapi_name() === 'cli') {
    if ($argc > 1) {
        $command = $argv[1];
        if ($command === 'install') {
            if ($argc < 3) {
                echo "Error: URL is required for install\n";
                echo "Usage: php setup.php install <url>\n";
                exit(1);
            }
            $url = $argv[2];
            create_app($url);
            echo "App generated successfully.\n";
            // After generating app, ask for auth generation
            init_database();
        } elseif ($command === 'update') {
            if ($argc < 3) {
                echo "Error: URL is required for update\n";
                echo "Usage: php setup.php update <url>\n";
                exit(1);
            }
            $url = $argv[2];
            create_app($url);
            echo "App generated successfully.\n";
            update_database();
            echo "Database updated successfully.\n";
        } elseif ($command === 'uninstall') {
            echo "Are you sure you want to delete this project? the database will also be deleted and cannot be recovered. (y/n): ";
            $confirm = trim(fgets(STDIN));
            if (strtolower($confirm) === 'y' || strtolower($confirm) === 'yes') {
                delete_app();
                echo "Project uninstalled successfully.\n";
            } else {
                echo "Uninstall cancelled.\n";
            }
        } else {
            echo "Usage: php setup.php install <url>\n";
            echo "Usage: php setup.php init_db\n";
            echo "Usage: php setup.php update_db\n";
            echo "Usage: php setup.php uninstall\n";
        }
    } else {
        echo "Usage: php setup.php install <url>\n";
        echo "Usage: php setup.php init_db\n";
        echo "Usage: php setup.php update_db\n";
        echo "Usage: php setup.php uninstall\n";
    }
    exit;
}

// Helper function to generate a short, unique foreign key constraint name
// MySQL max identifier length is 64 characters. This creates names like: fk_a1b2c3d4e5
function generate_fk_constraint_name($table, $column) {
    // Create a hash from table and column names
    $hash = substr(md5($table . '_' . $column), 0, 10);
    return 'fk_' . $hash;
}

function create_app($url = null){

    if (!$url) {
        echo "Error: URL is required.\n";
        echo "Usage: php setup.php install <url>\n";
        exit(1);
    }

    // Auto-prepend https:// if no protocol is specified
    // For localhost, do nothing - use URL as-is
    $url_to_fetch = $url;
    if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
        // Check if URL contains localhost - do NOT auto-prepend protocol
        if (strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false) {
            $url_to_fetch = $url;
        } else {
            $url_to_fetch = 'https://' . $url;
        }
    }

    $config_content = file_get_contents($url_to_fetch);
    if ($config_content === false) {
        echo "Error: Failed to fetch config from URL '$url_to_fetch'.\n";
        exit(1);
    }
    $config = json_decode($config_content, true);
    if ($config === null) {
        echo "Error: Invalid JSON in config fetched from URL.\n";
        exit(1);
    }

    $object_title_list = array_keys($config['object_models']);
    $object_models = [];
    foreach ($config['object_models'] as $title => $models_array) {
        $object_models = array_merge($object_models, $models_array);
    }

    // delete the channels and Library folders if exist to start fresh
    if (is_dir('channels')) {
        delete_directory('channels');
    }
    if (is_dir('Library')) {
        delete_directory('Library');
    }

    // Ensure Library directories exist
    $library_dirs = ['Library', 'Library/custom', 'Library/engine', 'Library/wrapper'];
    foreach ($library_dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    // Ensure channels directory exists
    if (!is_dir('channels')) {
        mkdir('channels', 0755, true);
        // creating channels/info.php for entire project
        if (file_exists('templates/_channels_info.txt')) {
            $channel_info = file_get_contents('templates/_channels_info.txt');
            file_put_contents('channels/info.php', $channel_info);
        }
    }

    // Save the fetched config to Library/config.json
    file_put_contents('Library/config.json', $config_content);

    $all_models = [];
    foreach ($object_models as $model_data) {
        $model = $model_data['model'];
        if (isset($model['name'])) {
            $all_models[] = ['name' => $model['name'], 'type' => 'main'];
        }
        if (isset($model_data['grouping_objects'])) {
            foreach ($model_data['grouping_objects'] as $group) {
                if (isset($group['name'])) {
                    $all_models[] = ['name' => $group['name'], 'type' => 'group'];
                }
            }
        }
        if (isset($model_data['child_objects'])) {
            foreach ($model_data['child_objects'] as $child) {
                if (isset($child['name'])) {
                    $all_models[] = ['name' => $child['name'], 'type' => 'child'];
                }
            }
        }
    }

    // custom functions
    foreach ($config['custom_functions'] as $func) {

        $filename = 'Library/custom/' . $func['name'] . '.php';

        $function_body = trim(str_replace(['<?php', '?>'], '', $func['function_text']));
        $content = "<?php\nfunction " . $func['name'] . "(\$DATA = array()) {\n" . $function_body . "\n}\n?>";

        file_put_contents($filename, $content);

    }

    // create folders based on channels
    foreach ($config['channels'] as $channel) {
        $channel_name = $channel['channel_name'];
        $folders = explode('/', $channel_name);
        $path = 'channels/';
        foreach ($folders as $folder) {
            if (!empty($folder)) {
                $path .= $folder . '/';
                if (!is_dir($path)) {
                    mkdir($path, 0755, true);
                    // create folder specs inside this folder
                    $path .= 'specs/';
                    if (!is_dir($path)) {
                        mkdir($path, 0755, true);
                    }
                }
            }
        }
    }

    // channel pages
    foreach ($config['channels'] as $channel) {
        if (isset($channel['pages'])) {
            foreach ($channel['pages'] as $page) {

                // create the endpoint file
                $code = $page['code'];
                $channel_name = $channel['channel_name'];
                $filename = 'channels/' . $channel_name . '/' . ltrim($page['endpoint'], '/');
                file_put_contents($filename, $code);
            
                // create the specs json file
                $specs = $page['specs'];
                $filename = 'channels/' . $channel_name . '/specs/' . pathinfo($specs['path'], PATHINFO_FILENAME) . '.json';
                file_put_contents($filename, json_encode($specs));
            }
        }
    }

    // create channel includes (policies)
    foreach ($config['channels'] as $channel) {
        if (isset($channel['include'])) {
            $include_code = $channel['include']['code'];
            $include_name = $channel['include']['name'];
            $channel_name = $channel['channel_name'];
            $filename = 'channels/' . $channel_name . '/' . $include_name;
            file_put_contents($filename, $include_code);
        }
    }

    // create api-spec.php for each channel — merges all specs/*.json into one JSON array
    foreach ($config['channels'] as $channel) {
        $channel_name = $channel['channel_name'];

        // Read the api-spec template
        if (!file_exists('templates/_setup_api_spec.txt')) {
            echo "Error: Template file 'templates/_setup_api_spec.txt' not found.\n";
            exit(1);
        }
        $api_spec_body = file_get_contents('templates/_setup_api_spec.txt');
        $api_spec_content = "<?php\n" . $api_spec_body . "?>";

        file_put_contents("channels/$channel_name/api-spec.php", $api_spec_content);
    }

    // object models - generate _LindseyEngine.php

    $content = $config['_LindseyEngine'];
    $content = str_replace("'main_objects'", "'object_models'", $content);

    file_put_contents('Library/engine/_LindseyEngine.php', $content);

    // generate model classes for all object_models and sub_models
    $all_models = [];
    foreach ($object_models as $model_data) {
        $model = $model_data['model'];
        if (isset($model['name'])) {
            $all_models[] = ['name' => $model['name'], 'type' => 'main'];
        }
        if (isset($model_data['grouping_objects'])) {
            foreach ($model_data['grouping_objects'] as $group) {
                if (isset($group['name'])) {
                    $all_models[] = ['name' => $group['name'], 'type' => 'group'];
                }
            }
        }
        if (isset($model_data['child_objects'])) {
            foreach ($model_data['child_objects'] as $child) {
                if (isset($child['name'])) {
                    $all_models[] = ['name' => $child['name'], 'type' => 'child'];
                }
            }
        }
    }

    // Build custom hooks map for inline embedding (key: object_method)
    $custom_hooks = [];
    foreach ($config['hooks'] as $hook) {
        $key = $hook['object_name'] . '_' . $hook['name'];
        $custom_hooks[$key] = trim(str_replace(['<?php', '?>'], '', $hook['function_text']));
    }

    foreach ($all_models as $model_info) {
        $obj = $model_info['name'];
        $class_name = str_replace(' ', '', ucwords(str_replace('_', ' ', $obj)));
        $model_content = "<?php\n\nclass $class_name extends _LindseyEngine {\n\n    public function __construct(\$config) {\n        parent::__construct('$obj', \$config);\n    }\n\n";

        $methods = ['get', 'set', 'delete'];
        // find state_variables for this model
        foreach ($object_models as $md) {
            $found = false;
            if (isset($md['model']['name']) && $md['model']['name'] == $obj) {
                $target = $md['model'];
                $found = true;
            } elseif (isset($md['grouping_objects'])) {
                foreach ($md['grouping_objects'] as $group) {
                    if (isset($group['name']) && $group['name'] == $obj) {
                        $target = $group;
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found && isset($md['child_objects'])) {
                foreach ($md['child_objects'] as $child) {
                    if (isset($child['name']) && $child['name'] == $obj) {
                        $target = $child;
                        $found = true;
                        break;
                    }
                }
            }
            if ($found && isset($target['state_machine']['state_variables'])) {
                foreach ($target['state_machine']['state_variables'] as $state_var) {
                    if (isset($state_var['name'])) {
                        $methods[] = 'set_' . $state_var['name'];
                    }
                }
            }
        }
        $methods = array_unique($methods);

        foreach ($methods as $method) {
            $params = '';
            if (strpos($method, 'set_') === 0 && $method != 'set') {
                $params = '$save_data';
            } elseif ($method == 'set') {
                $params = '$save_data';
            } elseif ($method == 'delete') {
                $params = '$id';
            } elseif ($method == 'get') {
                $params = '$parameters';
            }
            $hook_key = $obj . '_' . $method;
            if (isset($custom_hooks[$hook_key])) {
                // Embed custom hook code with proper indentation
                $custom_body = $custom_hooks[$hook_key];
                $indented_body = '        ' . str_replace("\n", "\n        ", $custom_body);
                $model_content .= "    public function $method($params) {\n$indented_body\n    }\n\n";
            } else {
                // Default hook template
                if (strpos($method, 'set_') === 0 && $method != 'set') {
                    // State-transition methods (e.g. set_published, set_prepared) delegate to
                    // parent::change_state with the target state derived from the method name
                    // (set_published -> 'published'), so the wrapper only takes $save_data.
                    $parent_method = 'change_state';
                    $state_name = substr($method, 4); // strip the leading 'set_'
                    $parent_call_params = "\$save_data, '$state_name'";
                } else {
                    $parent_method = $method;
                    $parent_call_params = $params;
                }
                $model_content .= "    public function $method($params) {\n        // **\n        // custom pre-hook goes there\n        // ..\n\n        // **\n        // calling the parent function\n        \$res = parent::$parent_method($parent_call_params);\n\n        // **\n        // custom post-hook goes there\n        // ..\n\n        // **\n        // done\n        return \$res;\n    }\n\n";
            }
        }

        // QUERYBUILDER wrappers — _get / _set / _delete are emitted automatically for
        // EVERY model. They are NOT driven by config hooks (custom_hooks) like get/set/delete
        // above; setup.php always generates them with the default pre/post-hook template.
        //
        // Split of responsibilities:
        //   get/set/delete   → the original array-based calls (and config-defined hooks). These
        //                      reject a QUERYBUILDER: array/scalar input only.
        //   _get/_set/_delete → the QUERYBUILDER entry points. These reject array/scalar input:
        //                      a QUERYBUILDER instance only.
        // Each QB wrapper delegates to the matching parent _get/_set/_delete engine method, which
        // enforces the QUERYBUILDER-only contract.
        $qb_methods = ['_get' => '_get', '_set' => '_set', '_delete' => '_delete'];
        foreach ($qb_methods as $qb_method => $parent_method) {
            $model_content .= "    public function $qb_method(\$qb) {\n        // **\n        // custom pre-hook goes there\n        // ..\n\n        // **\n        // calling the parent function\n        \$res = parent::$parent_method(\$qb);\n\n        // **\n        // custom post-hook goes there\n        // ..\n\n        // **\n        // done\n        return \$res;\n    }\n\n";
        }

        // custom functions
        foreach($object_title_list as $object_title) {
            foreach ($config['custom_functions'] as $func) {
                $expected_object_name = '$' . $object_title . '->' . $obj;
                if ($func['object_name'] === $expected_object_name) {
                    $model_content .= "    public function " . $func['name'] . "(\$DATA = array()) {\n        include_once __DIR__ . '/../custom/" . $func['name'] . ".php';\n        return " . $func['name'] . "(\$DATA);\n    }\n\n";
                }
            }
        }

        $model_content .= "}\n\n?>";

        file_put_contents("Library/wrapper/$class_name.php", $model_content);
    }

    // generate APP class if needed
    $app_methods = [];
    foreach ($config['custom_functions'] as $func) {
        if ($func['object_name'] === '$APP') {
            $app_methods[] = $func['name'];
        }
    }
    if (!empty($app_methods)) {
        $app_content = "<?php\n\nclass APP {\n\n";
        foreach ($app_methods as $method) {
            $app_content .= "    public function $method(\$DATA = array()) {\n        include_once __DIR__ . '/../custom/$method.php';\n        return $method(\$DATA);\n    }\n\n";
        }
        $app_content .= "}\n\n?>";
        file_put_contents("Library/wrapper/APP.php", $app_content);
    }
}

function init_database(){
    // Prompt for database details
    echo "Enter database host: ";
    $host = trim(fgets(STDIN));
    echo "Enter business database name: ";
    $db = trim(fgets(STDIN));
    echo "Enter database username: ";
    $user = trim(fgets(STDIN));
    echo "Enter database password: ";
    $pass = trim(fgets(STDIN));
    echo "Enter auth database name: ";
    $auth_db = trim(fgets(STDIN));
    echo "Enter Your Username to login in app runner: ";
    $admin_username = trim(fgets(STDIN));
    echo "Enter Your Password to login in app runner: ";
    $admin_password = trim(fgets(STDIN));

    // Check if template file exists
    if (!file_exists('templates/_db_config.txt')) {
        echo "Error: Template file 'templates/_db_config.txt' not found.\n";
        exit(1);
    }

    // Check if config.json exists
    if (!file_exists('Library/config.json')) {
        echo "Error: Config file 'Library/config.json' not found.\n";
        exit(1);
    }

    // Read the template
    $template = file_get_contents('templates/_db_config.txt');
    if ($template === false) {
        echo "Error: Failed to read template file.\n";
        exit(1);
    }

    // check the _db_config.php if not exist create
    if (!file_exists('_db_config.php')) {
        file_put_contents('_db_config.php', $template);
    }

    // Read and decode config
    $config_content = file_get_contents('Library/config.json');
    if ($config_content === false) {
        echo "Error: Failed to read config file.\n";
        exit(1);
    }
    $config = json_decode($config_content, true);
    if ($config === null) {
        echo "Error: Invalid JSON in config file.\n";
        exit(1);
    }

    $object_models = [];
    foreach ($config['object_models'] as $title => $models_array) {
        $object_models = array_merge($object_models, $models_array);
    }

    // Replace placeholders
    $template = str_replace('<HOST>', $host, $template);
    $template = str_replace('<DATABASE_NAME>', $db, $template);
    $template = str_replace('<USERNAME>', $user, $template);
    $template = str_replace('<PASSWORD>', $pass, $template);
    $template = str_replace('<AUTH_DATABASE_NAME>', $auth_db, $template);

    // Add charset to DSN
    $template = str_replace('$dsn = "mysql:host=$host;dbname=$db;";', '$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";', $template);

    // Now, add the table creation code inside the try block
    $table_creation_code = "\n    // Create tables based on config.json\n";
    $all_models_with_fields = [];
    foreach ($object_models as $model_data) {
        if (isset($model_data['model']) && isset($model_data['model']['name']) && isset($model_data['model']['fields'])) {
            $all_models_with_fields[] = $model_data['model'];
        }
        if (isset($model_data['grouping_objects'])) {
            foreach ($model_data['grouping_objects'] as $group) {
                if (isset($group['name']) && isset($group['fields'])) {
                    $all_models_with_fields[] = $group;
                }
            }
        }
        if (isset($model_data['child_objects'])) {
            foreach ($model_data['child_objects'] as $child) {
                if (isset($child['name']) && isset($child['fields'])) {
                    $all_models_with_fields[] = $child;
                }
            }
        }
    }
    foreach ($all_models_with_fields as $model) {
        $table_name = $model['name'];
        $table_creation_code .= "    \$pdo->exec(\"CREATE TABLE IF NOT EXISTS `$table_name` (\n";
        $fields_sql = [];
        foreach ($model['fields'] as $field) {
            $field_name = $field['name'];
            $type = map_field_type($field['type']);
            $null = $field['compulsory'] ? 'NOT NULL' : 'NULL';
            $default = isset($field['default_value']) && $field['default_value'] !== null ? "DEFAULT '" . addslashes($field['default_value']) . "'" : '';
            $fields_sql[] = "        `$field_name` $type $null $default";
        }
        // Add primary key if id exists
        if (in_array('id', array_column($model['fields'], 'name'))) {
            $fields_sql[] = "        PRIMARY KEY (`id`)";
        }
        $table_creation_code .= implode(",\n", $fields_sql) . "\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\");\n";
    }

    // Write to _db_config.php
    if (file_put_contents('_db_config.php', $template) === false) {
        echo "Error: Failed to write _db_config.php.\n";
        exit(1);
    }

    // Test the connection and create database if needed
    try {
        // First, connect without dbname to create the databases
        $dsn_no_db = "mysql:host=$host;charset=utf8mb4";
        $pdo_temp = new PDO($dsn_no_db, $user, $pass);
        $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS `$db`");

        // Create tables and insert user using template
        $auth_sql_template = file_get_contents('templates/_setup_auth.txt');
        $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
        $auth_sql = str_replace('<auth_database_name>', $auth_db, $auth_sql_template);
        $auth_sql = str_replace('<admin_username>', $admin_username, $auth_sql);
        $auth_sql = str_replace('<admin_password_hash>', $hashed_password, $auth_sql);

        $pdo_temp->exec($auth_sql);

        echo "Auth database, tables, and admin user created.\n";

        $pdo_temp = null; // close connection

        // Now include the config which has the full DSN
        include '_db_config.php';
        echo "Database connection successful.\n";

        // Now create tables
        $all_models_with_fields = [];
        foreach ($object_models as $model_data) {
            if (isset($model_data['model']) && isset($model_data['model']['name']) && isset($model_data['model']['fields'])) {
                $all_models_with_fields[] = $model_data['model'];
            }
            if (isset($model_data['grouping_objects'])) {
                foreach ($model_data['grouping_objects'] as $group) {
                    if (isset($group['name']) && isset($group['fields'])) {
                        $all_models_with_fields[] = $group;
                    }
                }
            }
            if (isset($model_data['child_objects'])) {
                foreach ($model_data['child_objects'] as $child) {
                    if (isset($child['name']) && isset($child['fields'])) {
                        $all_models_with_fields[] = $child;
                    }
                }
            }
        }
        // First, create all tables without foreign keys
        foreach ($all_models_with_fields as $model) {
            $table_name = $model['name'];
            $fields_sql = [];
            foreach ($model['fields'] as $field) {
                $field_name = $field['name'];
                $type = map_field_type($field['type']);
                $null = $field['compulsory'] ? 'NOT NULL' : 'NULL';
                $default = '';
                if (isset($field['default_value']) && $field['default_value'] !== null) {
                    if (strtoupper($field['default_value']) === 'CURRENT_TIMESTAMP') {
                        $default = "DEFAULT CURRENT_TIMESTAMP";
                    } elseif (is_bool($field['default_value'])) {
                        $default = $field['default_value'] ? "DEFAULT 1" : "DEFAULT 0";
                    } else {
                        $default = "DEFAULT '" . addslashes($field['default_value']) . "'";
                    }
                } elseif ($field['compulsory'] && in_array($field['type'], ['timestamp', 'datetime'])) {
                    $default = "DEFAULT CURRENT_TIMESTAMP";
                }
                $auto_increment = ($field_name == 'id') ? ' AUTO_INCREMENT' : '';
                $fields_sql[] = "`$field_name` $type $null $default$auto_increment";
            }
            // Add primary key if id exists
            if (in_array('id', array_column($model['fields'], 'name'))) {
                $fields_sql[] = "PRIMARY KEY (`id`)";
            }

            $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (\n" . implode(",\n", $fields_sql) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($sql);
            echo "Table `$table_name` created.\n";
        }

        // Then, add foreign key constraints
        foreach ($all_models_with_fields as $model) {
            $table_name = $model['name'];
            if (isset($model['relations'])) {
                foreach ($model['relations'] as $rel) {
                    if ($rel['type'] === 'belongs_to') {
                        $fk = $rel['foreign_key'];
                        $lk = $rel['local_key'];
                        $target = $rel['target'];
                        $sql = "ALTER TABLE `$table_name` ADD CONSTRAINT " . generate_fk_constraint_name($table_name, $lk) . " FOREIGN KEY (`{$lk}`) REFERENCES `{$target}` (`{$fk}`);";
                        $pdo->exec($sql);
                        echo "Foreign key added to `$table_name`.\n";
                    }
                }
            }
        }

        echo "All tables created successfully.\n";
    } catch (\PDOException $e) {
        echo "Database setup failed: " . $e->getMessage() . "\n";
    }
}

function update_database(){
    // Check if config.json exists
    if (!file_exists('Library/config.json')) {
        echo "Error: Config file 'Library/config.json' not found.\n";
        exit(1);
    }

    // Read and decode config
    $config_content = file_get_contents('Library/config.json');
    if ($config_content === false) {
        echo "Error: Failed to read config file.\n";
        exit(1);
    }
    $config = json_decode($config_content, true);
    if ($config === null) {
        echo "Error: Invalid JSON in config file.\n";
        exit(1);
    }

    $object_models = [];
    foreach ($config['object_models'] as $title => $models_array) {
        $object_models = array_merge($object_models, $models_array);
    }

    // Include DB config
    include '_db_config.php';

    // Get current tables
    $current_tables = [];
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = database()");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $current_tables[$row['table_name']] = [];
    }

    // Get columns for each table
    foreach ($current_tables as $table => &$columns) {
        $stmt = $pdo->prepare("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = database() AND table_name = ? ORDER BY ordinal_position");
        $stmt->execute([$table]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[$row['column_name']] = $row;
        }
    }

    // Get current foreign keys
    $current_fks = [];
    $stmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = database() AND REFERENCED_TABLE_NAME IS NOT NULL");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $table = $row['TABLE_NAME'];
        if (!isset($current_fks[$table])) $current_fks[$table] = [];
        $current_fks[$table][$row['COLUMN_NAME']] = [
            'constraint_name' => $row['CONSTRAINT_NAME'],
            'referenced_table' => $row['REFERENCED_TABLE_NAME'],
            'referenced_column' => $row['REFERENCED_COLUMN_NAME']
        ];
    }

    // Desired schema from config
    $desired_tables = [];
    $all_models_with_fields = [];
    foreach ($object_models as $model_data) {
        if (isset($model_data['model']) && isset($model_data['model']['name']) && isset($model_data['model']['fields'])) {
            $all_models_with_fields[] = $model_data['model'];
        }
        if (isset($model_data['grouping_objects'])) {
            foreach ($model_data['grouping_objects'] as $group) {
                if (isset($group['name']) && isset($group['fields'])) {
                    $all_models_with_fields[] = $group;
                }
            }
        }
        if (isset($model_data['child_objects'])) {
            foreach ($model_data['child_objects'] as $child) {
                if (isset($child['name']) && isset($child['fields'])) {
                    $all_models_with_fields[] = $child;
                }
            }
        }
    }
    foreach ($all_models_with_fields as $model) {
        $table_name = $model['name'];
        $desired_tables[$table_name] = [];
        foreach ($model['fields'] as $field) {
            $field_name = $field['name'];
            $type = map_field_type($field['type']);
            $nullable = $field['compulsory'] ? 'NO' : 'YES';
            $default = '';
            if (isset($field['default_value']) && $field['default_value'] !== null) {
                if (strtoupper($field['default_value']) === 'CURRENT_TIMESTAMP') {
                    $default = 'CURRENT_TIMESTAMP';
                } elseif (is_bool($field['default_value'])) {
                    $default = $field['default_value'] ? '1' : '0';
                } else {
                    $default = $field['default_value'];
                }
            } elseif ($field['compulsory'] && in_array($field['type'], ['timestamp', 'datetime'])) {
                $default = 'CURRENT_TIMESTAMP';
            }
            $desired_tables[$table_name][$field_name] = [
                'data_type' => $type,
                'is_nullable' => $nullable,
                'column_default' => $default,
                'default_value' => $field['default_value'] ?? null,
                'compulsory' => $field['compulsory'],
                'type' => $field['type']
            ];
        }
    }

    // Desired foreign keys
    $desired_fks = [];
    foreach ($all_models_with_fields as $model) {
        $table_name = $model['name'];
        if (!isset($desired_fks[$table_name])) $desired_fks[$table_name] = [];
        if (isset($model['relations'])) {
            foreach ($model['relations'] as $rel) {
                if ($rel['type'] === 'belongs_to') {
                    $lk = $rel['local_key'];
                    $target = $rel['target'];
                    $fk = $rel['foreign_key'];
                    $desired_fks[$table_name][$lk] = [
                        'referenced_table' => $target,
                        'referenced_column' => $fk
                    ];
                }
            }
        }
    }

    // Compare and generate actions
    $actions = [];

    // New tables
    foreach ($desired_tables as $table => $cols) {
        if (!isset($current_tables[$table])) {
            $actions[] = "CREATE TABLE `$table` (\n" . implode(",\n", array_map(function($name, $col) {
                $null = $col['is_nullable'] === 'NO' ? 'NOT NULL' : 'NULL';
                $def = $col['column_default'] ? "DEFAULT " . ($col['column_default'] === 'CURRENT_TIMESTAMP' ? 'CURRENT_TIMESTAMP' : "'$col[column_default]'") : '';
                $auto_inc = ($name == 'id') ? ' AUTO_INCREMENT' : '';
                return "`$name` {$col['data_type']} $null $def$auto_inc";
            }, array_keys($cols), $cols)) . (isset($cols['id']) ? ",\nPRIMARY KEY (`id`)" : "") . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        }
    }

    // Removed tables
    foreach ($current_tables as $table => $cols) {
        if (!isset($desired_tables[$table])) {
            $actions[] = "DROP TABLE `$table`;";
        }
    }


    // Changed tables
    foreach ($desired_tables as $table => $desired_cols) {
        if (isset($current_tables[$table])) {
            $current_cols = $current_tables[$table];

            // New columns
            foreach ($desired_cols as $col_name => $col) {
                if (!isset($current_cols[$col_name])) {
                    $null = $col['is_nullable'] === 'NO' ? 'NOT NULL' : 'NULL';
                    $def = '';
                    if (isset($col['default_value']) && $col['default_value'] !== null) {
                        if (strtoupper($col['default_value']) === 'CURRENT_TIMESTAMP') {
                            $def = "DEFAULT CURRENT_TIMESTAMP";
                        } elseif (is_bool($col['default_value'])) {
                            $def = $col['default_value'] ? "DEFAULT 1" : "DEFAULT 0";
                        } else {
                            $def = "DEFAULT '" . addslashes($col['default_value']) . "'";
                        }
                    } elseif ($col['compulsory'] && in_array($col['type'], ['timestamp', 'datetime'])) {
                        $def = "DEFAULT CURRENT_TIMESTAMP";
                    }
                    
                    $auto_inc = ($col_name == 'id') ? ' AUTO_INCREMENT' : '';
                    $actions[] = "ALTER TABLE `$table` ADD COLUMN `$col_name` {$col['data_type']} $null $def$auto_inc;";
                    if ($col_name == 'id') {
                        $actions[] = "ALTER TABLE `$table` ADD PRIMARY KEY (`id`);";
                    }
                }
            }

            // Removed columns
            foreach ($current_cols as $col_name => $col) {
                if (!isset($desired_cols[$col_name])) {
                    $actions[] = "ALTER TABLE `$table` DROP COLUMN `$col_name`;";
                }
            }

            // Changed columns
            foreach ($desired_cols as $col_name => $desired) {
                if (isset($current_cols[$col_name])) {
                    $current = $current_cols[$col_name];
                    $current_default = $current['column_default'];
                    
                    if ($current_default === null) $current_default = '';  // Normalize
                    if ($current_default === 'current_timestamp') $current_default = 'CURRENT_TIMESTAMP';
                    if ($desired['data_type'] === "VARCHAR(255)") $desired['data_type'] = "VARCHAR";  // Normalize
                    if ($desired["data_type"] === "VARCHAR(1000)") $desired["data_type"] = "VARCHAR";
                    if ($desired["data_type"] === "DECIMAL(10,2)") $desired["data_type"] = "DECIMAL";
                    if ($desired["data_type"] === "TINYINT(1)") $desired["data_type"] = "TINYINT";
                    

                    if (strtolower($current['data_type']) !== strtolower($desired['data_type']) ||
                        $current['is_nullable'] !== $desired['is_nullable'] ||
                        $current_default !== $desired['column_default']) {
                            $null = $desired['is_nullable'] === 'NO' ? 'NOT NULL' : 'NULL';
                            $def = $desired['column_default'] ? "DEFAULT " . ($desired['column_default'] === 'CURRENT_TIMESTAMP' ? 'CURRENT_TIMESTAMP' : "'$desired[column_default]'") : '';
                            $actions[] = "ALTER TABLE `$table` MODIFY COLUMN `$col_name` {$desired['data_type']} $null $def;";
                    }
                }
            }
        }
    }

    // Foreign key actions
    foreach ($desired_fks as $table => $fks) {
        if (!isset($current_fks[$table])) $current_fks[$table] = [];
        foreach ($fks as $lk => $desired) {
            if (!isset($current_fks[$table][$lk])) {
                $target = $desired['referenced_table'];
                $rfk = $desired['referenced_column'];
                $actions[] = "ALTER TABLE `$table` ADD CONSTRAINT " . generate_fk_constraint_name($table, $lk) . " FOREIGN KEY (`{$lk}`) REFERENCES `{$target}` (`{$rfk}`);";
            }
        }
    }
    foreach ($current_fks as $table => $fks) {
        if (!isset($desired_fks[$table])) $desired_fks[$table] = [];
        foreach ($fks as $lk => $current) {
            if (!isset($desired_fks[$table][$lk])) {
                $constraint = $current['constraint_name'];
                $actions[] = "ALTER TABLE `$table` DROP FOREIGN KEY `{$constraint}`;";
            }
        }
    }

    if (empty($actions)) {
        echo "No changes needed. Database is up to date.\n";
        return;
    }

    echo "Proposed changes:\n";
    $executed = [];
    foreach ($actions as $sql) {
        echo "Apply: $sql\n";
        echo "Confirm? (y/n): ";
        $input = trim(fgets(STDIN));
        if (strtolower($input) === 'y' || strtolower($input) === 'yes') {
            try {
                $pdo->exec($sql);
                echo "Executed successfully.\n";
                $executed[] = $sql;
            } catch (Exception $e) {
                echo "Error executing: " . $e->getMessage() . "\n";
            }
        } else {
            echo "Skipped.\n";
        }
    }

    if (!empty($executed)) {
        echo "Database update completed. " . count($executed) . " changes applied.\n";
    } else {
        echo "No changes applied.\n";
    }

    echo "Database update completed.\n";
}

function map_field_type($type) {
    switch ($type) {
        case 'integer':
            return 'BIGINT';
        case 'decimal':
            return 'DECIMAL(10,2)';
        case 'timestamp':
            return 'TIMESTAMP';
        case 'varchar_short':
            return 'VARCHAR(255)';
        case 'varchar_medium':
            return 'VARCHAR(1000)';
        case 'varchar_long':
            return 'TEXT';
        case 'text':
            return 'TEXT';
        case 'boolean':
            return 'TINYINT(1)';
        case 'date':
            return 'DATE';
        case 'datetime':
            return 'DATETIME';
        default:
            return 'VARCHAR(255)';
    }
}

function delete_directory($dirPath) {
    if (!is_dir($dirPath)) {
        return false;
    }
    // Get all files and subfolders, ignoring "." and ".."
    $files = array_diff(scandir($dirPath), array('.', '..'));
    
    foreach ($files as $file) {
        $path = "$dirPath/$file";
        // Recursively delete subdirectories, otherwise unlink files
        is_dir($path) ? delete_directory($path) : unlink($path);
    }
    // Finally, remove the empty root folder
    return rmdir($dirPath);
}

function delete_app() {
    // Delete channels folder if it exists
    if (is_dir('channels')) {
        delete_directory('channels');
        echo "Deleted 'channels' folder.\n";
    }

    // Delete Library folder if it exists
    if (is_dir('Library')) {
        delete_directory('Library');
        echo "Deleted 'Library' folder.\n";
    }

    // Delete databases defined in _db_config.php if it exists
    if (file_exists('_db_config.php')) {
        // Load DB credentials from _db_config.php
        include '_db_config.php';

        if (isset($host) && isset($user)) {
            // Connect without specifying a database so we can drop databases
            $dsn_no_db = "mysql:host=$host;charset=utf8mb4";
            try {
                $pdo_temp = new PDO($dsn_no_db, $user, $pass ?? '');
                $pdo_temp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Drop the business database
                if (isset($db)) {
                    $pdo_temp->exec("DROP DATABASE IF EXISTS `$db`");
                    echo "Dropped database '$db'.\n";
                }

                // Drop the auth database
                if (isset($auth_db)) {
                    $pdo_temp->exec("DROP DATABASE IF EXISTS `$auth_db`");
                    echo "Dropped auth database '$auth_db'.\n";
                }

                $pdo_temp = null;
            } catch (\PDOException $e) {
                echo "Warning: Could not drop databases: " . $e->getMessage() . "\n";
            }
        }

        // Delete the _db_config.php file itself
        unlink('_db_config.php');
        echo "Deleted '_db_config.php'.\n";
    }
}


?>