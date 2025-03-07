<?php

namespace App\Actions\Database;

use App\Models\StandaloneRedis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Lorisleiva\Actions\Concerns\AsAction;

class StartRedis
{
    use AsAction;

    public StandaloneRedis $database;
    public array $commands = [];
    public string $configuration_dir;


    public function handle(StandaloneRedis $database)
    {
        $this->database = $database;

        $startCommand = "redis-server --requirepass {$this->database->redis_password} --appendonly yes";

        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir() . '/' . $container_name;

        $this->commands = [
            "echo 'Starting {$database->name}.'",
            "mkdir -p $this->configuration_dir",
        ];

        $persistent_storages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();
        $this->add_custom_redis();

        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'command' => $startCommand,
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
                            'CMD-SHELL',
                            'redis-cli',
                            'ping'
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
        if (!is_null($this->database->redis_conf)) {
            $docker_compose['services'][$container_name]['volumes'][] = [
                'type' => 'bind',
                'source' => $this->configuration_dir . '/redis.conf',
                'target' => '/usr/local/etc/redis/redis.conf',
                'read_only' => true,
            ];
            $docker_compose['services'][$container_name]['command'] = "redis-server /usr/local/etc/redis/redis.conf --requirepass {$this->database->redis_password} --appendonly yes";
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
        foreach ($this->database->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->value");
        }

        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('REDIS_PASSWORD'))->isEmpty()) {
            $environment_variables->push("REDIS_PASSWORD={$this->database->redis_password}");
        }

        return $environment_variables->all();
    }
    private function add_custom_redis()
    {
        if (is_null($this->database->redis_conf)) {
            return;
        }
        $filename = 'redis.conf';
        Storage::disk('local')->put("tmp/redis.conf_{$this->database->uuid}", $this->database->redis_conf);
        $path = Storage::path("tmp/redis.conf_{$this->database->uuid}");
        instant_scp($path, "{$this->configuration_dir}/{$filename}", $this->database->destination->server);
        Storage::disk('local')->delete("tmp/redis.conf_{$this->database->uuid}");
    }
}
