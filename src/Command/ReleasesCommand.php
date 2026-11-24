<?php declare(strict_types=1);

namespace Rush\Tool\Code\Command;

use Rush\Tool\Code\Exception\ConfigException;
use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Event\DeployEvent;
use Rush\Tool\Code\Release\ReleaseStore;
use Rush\Tool\Code\Support\Json;


final class ReleasesCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'code releases';
    }

    public function description(): string
    {
        return 'List local releases or sync all targets';
    }

    public function usage(): string
    {
        return 'rush code releases [--target=<target>] | --sync';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('target', 'Analyze a single target in local history', true, null, false, 'target'),
            new Option('sync', 'Sync all targets; must be used alone'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        if ($input->bool('sync')) {
            if ($input->has('target')) {
                throw new ConfigException('--sync cannot be used with --target');
            }

            return $this->syncReleases($output) ? ExitCode::Success->value : ExitCode::Failure->value;
        }

        $target = $input->string('target');

        if ($target !== null && $target !== '') {
            $this->config()->target($target);
        }

        $releases = $this->localReleases($target);

        $this->render($releases, $output);

        return ExitCode::Success->value;
    }


    /**
     * @return list<array{manifest: array<string, mixed>, events: list<array<string, mixed>>}>
     */
    private function localReleases(?string $target): array
    {
        $store = new ReleaseStore($this->projectRoot);
        $manifests = [];

        foreach ($store->releaseIds() as $releaseId) {
            $manifests[$releaseId] = $store->manifest($releaseId);
        }

        $events = [];

        foreach (glob(DeployPaths::localEvents($this->projectRoot) .'/*.json') ?: [] as $file) {
            $data = Json::read($file, 'deploy event');

            $event = DeployEvent::fromArray($data);

            if ($target !== null && $target !== '' && $event->target !== $target) {
                continue;
            }

            $events[$event->releaseId][] = $event->toArray();
        }

        $releases = [];

        foreach ($manifests as $releaseId => $manifest) {
            $releaseEvents = $events[$releaseId] ?? [];

            if (($target !== null && $target !== '') && $releaseEvents === []) {
                continue;
            }

            usort(
                $releaseEvents,
                static fn (array $left, array $right): int => self::text($left['at'] ?? '') <=> self::text($right['at'] ?? ''),
            );

            $releases[] = [
                'manifest' => $manifest->toArray(),
                'events' => $releaseEvents,
            ];
        }

        usort(
            $releases,
            static fn (array $left, array $right): int => self::text($right['manifest']['createdAt'] ?? '')
                <=> self::text($left['manifest']['createdAt'] ?? ''),
        );

        return $releases;
    }


    /** @param list<array{manifest: array<string, mixed>, events: list<array<string, mixed>>}> $releases */
    private function render(array $releases, OutputInterface $output): void
    {
        if ($releases === []) {
            $output->info('no releases');

            return;
        }

        foreach ($releases as $release) {
            $manifest = $release['manifest'];
            $createdBy = $this->operatorLabel($manifest['createdBy'] ?? []);
            $output->line(
                'release '. self::text($manifest['releaseId'] ?? '-') .'  v'. self::text($manifest['version'] ?? '0.0.0'),
            );
            $output->line(
                '  created '. self::text($manifest['createdAt'] ?? '-') .' by '. $createdBy,
            );

            foreach ($release['events'] as $event) {
                $output->line('  '. $this->eventLine($event));
            }

            $output->line();
        }
    }


    /** @param array<string, mixed> $event */
    private function eventLine(array $event): string
    {
        $action = str_pad(self::text($event['action'] ?? '-'), 8);
        $from = $event['from'] ?? null;
        $to = $event['to'] ?? null;
        $route = is_string($from) && is_string($to) ? "{$from} -> {$to}" : self::text($to ?? $event['target'] ?? '-');

        return $action .' '. str_pad($route, 36) .' '
            . self::text($event['at'] ?? '-') .' by '. $this->operatorLabel($event['by'] ?? []);
    }


    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '-';
    }


    /** @param mixed $operator */
    private function operatorLabel($operator): string
    {
        if ( ! is_array($operator) || ! is_string($operator['name'] ?? null)) {
            return 'unknown';
        }

        $email = $operator['email'] ?? null;

        return is_string($email) && $email !== ''
            ? $operator['name'] .' <'. $email .'>'
            : $operator['name'];
    }
}
