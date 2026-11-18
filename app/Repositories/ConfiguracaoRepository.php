<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Configurações soltas do sistema (tabela app_settings: meta de CAC, ordem de desempate, estilo dos benefícios...). */
final class ConfiguracaoRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** Valor da configuração, ou $padrao se ela não existir (mesmo comportamento do getSettingValue do config.php). */
    public function valor(string $chave, ?string $padrao = null): ?string
    {
        $linha = $this->banco->linha('SELECT setting_value FROM app_settings WHERE setting_key = ?', [$chave]);
        return $linha === null ? $padrao : $linha['setting_value'];
    }

    /** Grava (ou troca) o valor de uma configuração. Mesmo efeito do setSettingValue do config.php. */
    public function definir(string $chave, string $valor): void
    {
        $this->banco->executar(
            'INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',
            [$chave, $valor]
        );
    }

    /** Quantos dias tem a semana de contratos (1 a 14; padrão 7). Mesmo cálculo do getWeekDayCount do config.php. */
    public function diasDaSemana(): int
    {
        return max(1, min(14, (int) $this->valor('week_days', '7')));
    }
}
