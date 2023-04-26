<x-layout>
    <h1>Projects <a href="{{ route('project.new') }}"><button>New</button></a></h1>
    @forelse ($projects as $project)
        <a href="{{ route('project.environments', [$project->uuid]) }}">{{ data_get($project, 'name') }}</a>
    @empty
        <p>No projects found.</p>
    @endforelse
    <h1>Servers</h1>
    @forelse ($servers as $server)
        <a href="{{ route('server.dashboard', [$server->uuid]) }}">{{ data_get($server, 'name') }}</a>
    @empty
        <p>No servers found.</p>
    @endforelse
</x-layout>