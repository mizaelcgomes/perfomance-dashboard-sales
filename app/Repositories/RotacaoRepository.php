<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * Rotação do painel da TV (ordem e duração dos itens) e a próxima música de fundo. Ficam em app_settings
 * (chaves "rotation" e "bg_next_track"), como sempre foi.
 */
final class RotacaoRepository
{
    public function __construct(private ConfiguracaoRepository $config)
    {
    }

    /** Itens da rotação; vazio quando nada foi salvo ou o valor guardado não é uma lista. @return array<int|string, mixed> */
    public function itens(): array
    {
        $bruto = $this->config->valor('rotation', '');
        $dados = $bruto ? json_decode((string) $bruto, true) : [];
        return is_array($dados) ? $dados : [];
    }

    /** @param array<int, array<string, mixed>> $itens */
    public function salvar(array $itens): void
    {
        $this->config->definir('rotation', (string) json_encode($itens));
    }

    /** Nome da música que o admin mandou tocar a seguir ('' = nenhuma). */
    public function proximaMusica(): string
    {
        return (string) $this->config->valor('bg_next_track', '');
    }

    public function definirProximaMusica(string $nome): void
    {
        $this->config->definir('bg_next_track', $nome);
    }
}
