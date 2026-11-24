<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy;

use Rush\Tool\Code\Shell;


final class ReleaseVersion
{
    /** @return array{version: string, source: string, git: null|array{commit: string, branch: string, dirty: bool}} */
    public function resolve(string $projectRoot, Shell $shell): array
    {
        $versionFile = rtrim($projectRoot, '/') .'/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        $source = 'version';

        if ($version === '') {
            $env = getenv('DEPLOY_VERSION');
            $version = is_string($env) ? trim($env) : '';
            $source = 'env';
        }

        if ($version === '') {
            $version = '0.0.0';
            $source = 'none';
        }

        return ['version' => $version, 'source' => $source, 'git' => $this->gitInfo($projectRoot, $shell)];
    }


    /** @return null|array{commit: string, branch: string, dirty: bool} */
    private function gitInfo(string $projectRoot, Shell $shell): ?array
    {
        $commit = $shell->run(['git', '-C', $projectRoot, 'rev-parse', '--short', 'HEAD']);

        if ( ! $commit->ok() || trim($commit->stdout) === '') {
            return null;
        }

        $branch = $shell->run(['git', '-C', $projectRoot, 'rev-parse', '--abbrev-ref', 'HEAD']);
        $status = $shell->run(['git', '-C', $projectRoot, 'status', '--porcelain']);

        return [
            'commit' => trim($commit->stdout),
            'branch' => $branch->ok() ? trim($branch->stdout) : '',
            'dirty' => $status->ok() && trim($status->stdout) !== '',
        ];
    }
}
