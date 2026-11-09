<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Install;

use VenPosTPV\Service\Provisioning\ProvisioningReport;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;

/** The «Estado de la instalación» block of the installer's last page. */
final class ProvisioningSummary
{
    public static function html(?ProvisioningReport $report): string
    {
        $html = '<h2>Estado de la instalación</h2>';
        $manual = '<code>php bin/console tpv:provision --env=prod</code>';
        if ($report === null) {
            return $html . '<p class="err">No se han podido comprobar los pasos de la instalación (el detalle está en el registro de errores de PHP). '
                . 'Se completarán solos con la tarea programada de cada minuto, o ejecuta ' . $manual . '. Su estado se ve en Ajustes → Acerca de.</p>';
        }

        $rows = '';
        foreach ($report->steps as $step) {
            $state = $step->isDone() ? 'Hecho' : 'Pendiente' . ($step->reason !== null ? ': ' . $step->reason : '');
            $rows .= '<tr><td>' . self::h($step->description) . '</td><td>' . self::h($state) . '</td></tr>';
        }
        $html .= '<div class="table"><table><tr><th>Paso</th><th>Estado</th></tr>' . $rows . '</table></div>';
        if ($report->isComplete()) {
            return $html . '<p class="ok">Todos los pasos de la instalación están hechos.</p>';
        }

        return $html . '<p>Los pasos pendientes se reintentan solos cada ' . intdiv(ProvisioningRunner::RETRY_AFTER_SECONDS, 60)
            . ' minutos con la tarea programada de cada minuto (<code>tpv:sync:outbox</code>), o en cuanto ejecutes ' . $manual . '. '
            . 'Es normal que los de PrestaShop sigan pendientes hasta que el módulo TPV Connect esté instalado y configurado (apartado 3). '
            . 'Su estado se ve en Ajustes → Acerca de.</p>';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
