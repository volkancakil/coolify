<?php

namespace App\Livewire\Source\Github;

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use Livewire\Component;

class Change extends Component
{
    public string $webhook_endpoint;
    public ?string $ipv4;
    public ?string $ipv6;
    public ?string $fqdn;

    public ?bool $default_permissions = true;
    public ?bool $preview_deployment_permissions = true;

    public $parameters;
    public GithubApp $github_app;
    public string $name;
    public bool $is_system_wide;

    protected $rules = [
        'github_app.name' => 'required|string',
        'github_app.organization' => 'nullable|string',
        'github_app.api_url' => 'required|string',
        'github_app.html_url' => 'required|string',
        'github_app.custom_user' => 'required|string',
        'github_app.custom_port' => 'required|int',
        'github_app.app_id' => 'required|int',
        'github_app.installation_id' => 'required|int',
        'github_app.client_id' => 'required|string',
        'github_app.client_secret' => 'required|string',
        'github_app.webhook_secret' => 'required|string',
        'github_app.is_system_wide' => 'required|bool',
    ];

    public function mount()
    {
        $github_app_uuid = request()->github_app_uuid;
        $this->github_app = GithubApp::where('uuid', $github_app_uuid)->first();
        if (!$this->github_app) {
            return redirect()->route('source.all');
        }
        $settings = InstanceSettings::get();
        $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');

        $this->name = str($this->github_app->name)->kebab();
        $this->fqdn = $settings->fqdn;

        if ($settings->public_ipv4) {
            $this->ipv4 = 'http://' . $settings->public_ipv4 . ':' . config('app.port');
        }
        if ($settings->public_ipv6) {
            $this->ipv6 = 'http://' . $settings->public_ipv6 . ':' . config('app.port');
        }
        if ($this->github_app->installation_id && session('from')) {
            $source_id = data_get(session('from'), 'source_id');
            if (!$source_id || $this->github_app->id !== $source_id) {
                session()->forget('from');
            } else {
                $parameters = data_get(session('from'), 'parameters');
                $back = data_get(session('from'), 'back');
                $environment_name = data_get($parameters, 'environment_name');
                $project_uuid = data_get($parameters, 'project_uuid');
                $type = data_get($parameters, 'type');
                $destination = data_get($parameters, 'destination');
                session()->forget('from');
                return redirect()->route($back, [
                    'environment_name' => $environment_name,
                    'project_uuid' => $project_uuid,
                    'type' => $type,
                    'destination' => $destination,
                ]);
            }
        }
        $this->parameters = get_route_parameters();
        if (isCloud() && !isDev()) {
            $this->webhook_endpoint = config('app.url');
        } else {
            $this->webhook_endpoint = $this->ipv4;
            $this->is_system_wide = $this->github_app->is_system_wide;
        }
    }

    public function submit()
    {
        try {
            $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');
            $this->validate([
                'github_app.name' => 'required|string',
                'github_app.organization' => 'nullable|string',
                'github_app.api_url' => 'required|string',
                'github_app.html_url' => 'required|string',
                'github_app.custom_user' => 'required|string',
                'github_app.custom_port' => 'required|int',
                'github_app.app_id' => 'required|int',
                'github_app.installation_id' => 'required|int',
                'github_app.client_id' => 'required|string',
                'github_app.client_secret' => 'required|string',
                'github_app.webhook_secret' => 'required|string',
                'github_app.is_system_wide' => 'required|bool',
            ]);
            $this->github_app->save();
            $this->dispatch('success', 'Github App updated successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
    }

    public function delete()
    {
        try {
            $this->github_app->delete();
            return redirect()->route('source.all');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
