<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Pop-up avulso da TV, disparado à mão pelo admin (tabela custom_popup, uma linha só: id=1). */
final class PopupRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** titulo, foto1, foto2 e triggered_at, ou null se a linha não existir. @return array<string, mixed>|null */
    public function atual(): ?array
    {
        return $this->banco->linha('SELECT titulo, foto1, foto2, triggered_at FROM custom_popup WHERE id=1');
    }

    public function salvar(string $titulo, ?string $foto1, ?string $foto2): void
    {
        $this->banco->executar('UPDATE custom_popup SET titulo=?, foto1=?, foto2=? WHERE id=1', [$titulo, $foto1, $foto2]);
    }

    /** Marca o disparo agora: a TV mostra o pop-up quando vê um triggered_at novo. */
    public function disparar(): void
    {
        $this->banco->executar('UPDATE custom_popup SET triggered_at = NOW() WHERE id=1');
    }
}
