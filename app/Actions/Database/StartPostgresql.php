<?php

namespace App\Actions\Database;

use App\Models\StandalonePostgresql;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Lorisleiva\Actions\Concerns\AsAction;

class StartPostgresql
{
    use AsAction;

    public StandalonePostgresql $database;
    public array $commands = [];
    public array $init_scripts = [];
    public string $configuration_dir;

    public function handle(StandalonePostgresql $database)
    {
        $this->database = $database;
        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir() . '/' . $container_name;

        $this->commands = [
            "echo 'Starting {$database->name}.'",
            "mkdir -p $this->configuration_dir",
            "mkdir -p $this->configuration_dir/docker-entrypoint-initdb.d/"
        ];

        $persistent_storages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();
        $this->generate_init_scripts();
        $this->add_custom_conf();

        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'container_name' => $container_name,
                    'environment' => $environment_variables,
                    'restart' => RESTART_MODE,
                    'networks' => [
                        $this->database->destination->network,
                    ],
                    'labels' => [
                        'coolify.managed' => 'true',
                    ],
                    'healthcheck' => [
                        'test' => [
                            "CMD-SHELL",
                            "psql -U {$this->database->postgres_user} -d {$this->database->postgres_db} -c 'SELECT 1' || exit 1"
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 10,
                        'start_period' => '5s'
                    ],
                    'mem_limit' => $this->database->limits_memory,
                    'memswap_limit' => $this->database->limits_memory_swap,
                    'mem_swappiness' => $this->database->limits_memory_swappiness,
                    'mem_reservation' => $this->database->limits_memory_reservation,
                    'cpus' => (float) $this->database->limits_cpus,
                    'cpu_shares' => $this->database->limits_cpu_shares,
                ]
            ],
            'networks' => [
                $this->database->destination->network => [
                    'external' => true,
                    'name' => $this->database->destination->network,
                    'attachable' => true,
                ]
            ]
        ];
        if (!is_null($this->database->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->database->limits_cpuset);
        }
        if ($this->database->destination->server->isLogDrainEnabled() && $this->database->isLogDrainEnabled()) {
            ray('Log Drain Enabled');
            $docker_compose['services'][$container_name]['logging'] = [
                'driver' => 'fluentd',
                'options' => [
                    'fluentd-address' => "tcp://127.0.0.1:24224",
                    'fluentd-async' => "true",
                    'fluentd-sub-second-precision' => "true",
                ]
            ];
        }
        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        }
        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = $persistent_storages;
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }
        if (count($this->init_scripts) > 0) {
            foreach ($this->init_scripts as $init_script) {
                $docker_compose['services'][$container_name]['volumes'][] = [
                    'type' => 'bind',
                    'source' => $init_script,
                    'target' => '/docker-entrypoint-initdb.d/' . basename($init_script),
                    'read_only' => true,
                ];
            }
        }
        if (!is_null($this->database->postgres_conf)) {
            $docker_compose['services'][$container_name]['volumes'][] = [
                'type' => 'bind',
                'source' => $this->configuration_dir . '/custom-postgres.conf',
                'target' => '/etc/postgresql/postgresql.conf',
                'read_only' => true,
            ];
            $docker_compose['services'][$container_name]['command'] = [
                'postgres',
                '-c',
                'config_file=/etc/postgresql/postgresql.conf',
            ];
        }
        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d > $this->configuration_dir/docker-compose.yml";
        $readme = generate_readme_file($this->database->name, now());
        $this->commands[] = "echo '{$readme}' > $this->configuration_dir/README.md";
        $this->commands[] = "echo 'Pulling {$database->image} image.'";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml pull";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml up -d";
        $this->commands[] = "echo '{$database->name} started.'";
        return remote_process($this->commands, $database->destination->server, callEventOnFinish: 'DatabaseStatusChanged');
    }

    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            $volume_name = $persistentStorage->host_path ?? $persistentStorage->name;
            $local_persistent_volumes[] = $volume_name . ':' . $persistentStorage->mount_path;
        }
        return $local_persistent_volumes;
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        $local_persistent_volumes_names = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;
            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }
        return $local_persistent_volumes_names;
    }

    private function generate_environment_variables()
    {
        $environment_variables = collect();
        ray('Generate Environment Variables')->green();
        ray($this->database->runtime_environment_variables)->green();
        foreach ($this->database->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->value");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('POSTGRES_USER'))->isEmpty()) {
            $environment_variables->push("POSTGRES_USER={$this->database->postgres_user}");
        }
        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('PGUSER'))->isEmpty()) {
            $environment_variables->push("PGUSER={$this->database->postgres_user}");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('POSTGRES_PASSWORD'))->isEmpty()) {
            $environment_variables->push("POSTGRES_PASSWORD={$this->database->postgres_password}");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('POSTGRES_DB'))->isEmpty()) {
            $environment_variables->push("POSTGRES_DB={$this->database->postgres_db}");
        }
        return $environment_variables->all();
    }

    private function generate_init_scripts()
    {
        if (is_null($this->database->init_scripts) || count($this->database->init_scripts) === 0) {
            return;
        }
        foreach ($this->database->init_scripts as $init_script) {
            $filename = data_get($init_script, 'filename');
            $content = data_get($init_script, 'content');
            $content_base64 = base64_encode($content);
            $this->commands[] = "echo '{$content_base64}' | base64 -d > $this->configuration_dir/docker-entrypoint-initdb.d/{$filename}";
            $this->init_scripts[] = "$this->configuration_dir/docker-entrypoint-initdb.d/{$filename}";
        }
    }
    private function add_custom_conf()
    {
        if (is_null($this->database->postgres_conf)) {
            return;
        }
        $filename = 'custom-postgres.conf';
        $content = $this->database->postgres_conf;
        $content_base64 = base64_encode($content);
        $this->commands[] = "echo '{$content_base64}' | base64 -d > $this->configuration_dir/{$filename}";
    }
}
