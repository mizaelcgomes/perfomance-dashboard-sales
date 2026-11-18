<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\LiderPortalRepository;
use App\Repositories\VendedorRepository;

/**
 * Busca global do portal do líder (Ctrl+K): closers da equipe e registros da equipe (reuniões, feedbacks, cobranças, revisões e treinamentos).
 * Só a equipe do líder. O texto vira um LIKE com os curingas (% e _) escapados, então o que o usuário digita nunca é interpretado como padrão.
 */
final class LiderBuscaService
{
    public const MINIMO = 2;

    public function __construct(private VendedorRepository $vendedores, private LiderPortalRepository $portal)
    {
    }

    /**
     * @param mixed $consulta
     * @return array{closers: array<int, array<string, mixed>>, registros: array<int, array<string, mixed>>}
     */
    public function buscar(int $equipeId, int $liderVendedorId, $consulta): array
    {
        $q = RegistroValidador::texto($consulta, 60);
        if ($q === null || RegistroValidador::tamanho($q) < self::MINIMO) {
            return ['closers' => [], 'registros' => []];
        }
        $closers = [];
        foreach ($this->vendedores->doTime($equipeId) as $v) {
            if ($v['id'] !== $liderVendedorId && stripos($this->semAcento($v['nome']), $this->semAcento($q)) !== false) {
                $closers[] = ['id' => $v['id'], 'nome' => $v['nome']];
            }
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $linhas = $this->portal->buscarRegistros($equipeId, $like, Telefone::digitos($q), 5);
        $nomes = $this->vendedores->nomes(array_filter(array_map(static fn(array $r): int => (int) ($r['closer_vendedor_id'] ?? 0), $linhas)));
        $registros = array_map(static function (array $r) use ($nomes): array {
            $detalhe = (string) ($r['detalhe'] ?? '');
            return [
                'tipo' => (string) $r['tipo'], 'id' => (int) $r['id'], 'titulo' => (string) $r['titulo'],
                'detalhe' => RegistroValidador::tamanho($detalhe) > 110 && preg_match('/^.{110}/us', $detalhe, $m) === 1 ? $m[0] . '…' : $detalhe,
                'quando' => (string) ($r['quando'] ?? ''), 'closer_nome' => $r['closer_vendedor_id'] === null ? null : ($nomes[(int) $r['closer_vendedor_id']] ?? null),
            ];
        }, $linhas);
        return ['closers' => array_slice($closers, 0, 8), 'registros' => $registros];
    }

    /** Minúsculas e sem acento (para achar "joão" digitando "joao"), sem depender da extensão intl. */
    private function semAcento(string $t): string
    {
        $mapa = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
                 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'É' => 'e', 'Ê' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ú' => 'u', 'Ç' => 'c'];
        return strtolower(strtr($t, $mapa));
    }
}
