<?php

namespace App\Livewire\Server\Proxy;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Events\ProxyStatusChanged;
use App\Models\Server;
use Livewire\Component;

class Deploy extends Component
{
    public Server $server;
    public bool $traefikDashboardAvailable = false;
    public ?string $currentRoute = null;
    public ?string $serverIp = null;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;
        return [
            "echo-private:team.{$teamId},ProxyStatusChanged" => 'proxyStarted',
            'proxyStatusUpdated',
            'traefikDashboardAvailable',
            'serverRefresh' => 'proxyStatusUpdated',
            "checkProxy", "startProxy"
        ];
    }

    public function mount()
    {
        if ($this->server->id === 0) {
            $this->serverIp = base_ip();
        } else {
            $this->serverIp = $this->server->ip;
        }
        $this->currentRoute = request()->route()->getName();
    }
    public function traefikDashboardAvailable(bool $data)
    {
        $this->traefikDashboardAvailable = $data;
    }
    public function proxyStarted()
    {
        CheckProxy::run($this->server, true);
        $this->dispatch('success', 'Proxy started.');
    }
    public function proxyStatusUpdated()
    {
        $this->server->refresh();
    }
    public function restart() {
        try {
            $this->stop();
            $this->dispatch('checkProxy');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function checkProxy()
    {
        try {
            CheckProxy::run($this->server, true);
            $this->dispatch('startProxyPolling');
            $this->dispatch('proxyChecked');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function startProxy()
    {
        try {
            $activity = StartProxy::run($this->server);
            $this->dispatch('newMonitorActivity', $activity->id, ProxyStatusChanged::class);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stop()
    {
        try {
            if ($this->server->isSwarm()) {
                instant_remote_process([
                    "docker service rm coolify-proxy_traefik",
                ], $this->server);
                $this->server->proxy->status = 'exited';
                $this->server->save();
                $this->dispatch('proxyStatusUpdated');
            } else {
                instant_remote_process([
                    "docker rm -f coolify-proxy",
                ], $this->server);
                $this->server->proxy->status = 'exited';
                $this->server->save();
                $this->dispatch('proxyStatusUpdated');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
