<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\EquipeRepository;

/** As equipes como o admin as lista (aba Time): contagens, vendas, metas, score e o líder (só vale se ainda for closer ativo da equipe). */
final class EquipeService
{
    public function __construct(private EquipeRepository $equipes, private ImagemService $imagens)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function lista(): array
    {
        return array_map(function (array $r): array {
            $r['imageUrl'] = $this->imagens->url($r['img_url']);
            $r['lider_id'] = $r['lider_nome'] !== null ? (int) $r['lider_id'] : null;
            $r['total_closers'] = (int) $r['total_closers'];
            $r['total_vendas'] = (int) $r['total_vendas'];
            $r['total_metas'] = (int) $r['total_metas'];
            $r['score'] = $r['total_metas'] > 0 ? min(100, (int) round($r['total_vendas'] / $r['total_metas'] * 100)) : 0;
            return $r;
        }, $this->equipes->comTotais());
    }
}
