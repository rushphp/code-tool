<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline;

use Throwable;


final class RollbackGuard
{
    public static function recoverDeployment(DeployContext $context, Throwable $error): void
    {
        if ( ! $context->maintenanceActive) {
            return;
        }

        $context->out('error: '. $error->getMessage());

        if ($context->snapshot === null) {
            $context->out('no backup: maintenance remains active');

            return;
        }

        try {
            self::restore($context, $context->snapshot);
            $context->out('rollback complete');
        } catch (Throwable $rollback) {
            $context->out('ROLLBACK FAILED, maintenance remains active: '. $rollback->getMessage());
        }
    }


    public static function recoverRetirement(DeployContext $context, Throwable $error): void
    {
        if ( ! $context->maintenanceActive || $context->snapshot === null) {
            return;
        }

        try {
            $context->maintenance->enable($context->target);
            $context->snapshots->restore($context->snapshot);
            $context->transfer->applyOverrides(false);
        } catch (Throwable $rollback) {
            $context->out('RETIRE ROLLBACK FAILED, maintenance remains active: '. $rollback->getMessage());
        }
    }


    public static function recoverRollback(DeployContext $context): void
    {
        if ( ! $context->maintenanceActive) {
            return;
        }

        try {
            $context->maintenance->enable($context->target);
        } catch (Throwable) {
            // Maintenance may already be active; keep the original failure.
        }
    }


    private static function restore(DeployContext $context, string $snapshot): void
    {
        $context->maintenance->enable($context->target);
        $context->snapshots->restore($snapshot);
        $context->transfer->applyOverrides($context->hasRsync);
        $context->maintenance->disable($context->target);
        $context->verifier->verify();
    }
}
