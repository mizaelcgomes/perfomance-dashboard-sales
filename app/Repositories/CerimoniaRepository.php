<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Cerimônia de entrega das metas da semana anterior (tabela goal_ceremony, uma linha só: id=1). */
final class CerimoniaRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** Música e duração da tela final, ou null se a linha não existir. @return array<string, mixed>|null */
    public function configuracao(): ?array
    {
        return $this->banco->linha('SELECT musica, final_duration FROM goal_ceremony WHERE id=1');
    }

    /** Tudo que a TV lê: o que foi disparado (payload e quando) mais a configuração. @return array<string, mixed>|null */
    public function estado(): ?array
    {
        return $this->banco->linha('SELECT payload, triggered_at, musica, final_duration FROM goal_ceremony WHERE id=1');
    }

    public function salvarConfiguracao(?string $musica, int $duracaoFinal): void
    {
        $this->banco->executar('UPDATE goal_ceremony SET musica=?, final_duration=? WHERE id=1', [$musica, $duracaoFinal]);
    }

    /** Guarda os homenageados e marca o disparo agora (a TV abre a cerimônia ao ver um triggered_at novo). */
    public function disparar(string $payloadJson): void
    {
        $this->banco->executar('UPDATE goal_ceremony SET payload=?, triggered_at=NOW() WHERE id=1', [$payloadJson]);
    }
}
