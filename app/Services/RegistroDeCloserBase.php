<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\VendedorRepository;

/**
 * Base dos registros que o líder faz SOBRE um closer da equipe (feedback, cobrança de cadastramento e revisão de atendimento).
 * A regra de ouro está aqui: o closer só vale se for da equipe DO LÍDER (equipe que vem do middleware, nunca da requisição) e não for o
 * próprio líder. Assim um líder não consegue gravar nada sobre closer de outra equipe, mesmo que mande o id dele no corpo.
 */
abstract class RegistroDeCloserBase
{
    public function __construct(protected VendedorRepository $vendedores)
    {
    }

    /** A mensagem de erro do closer escolhido, ou null se ele serve. @param mixed $closerId */
    protected function erroDoCloser(int $equipeId, int $liderVendedorId, $closerId): ?string
    {
        $id = is_numeric($closerId) ? (int) $closerId : 0;
        if ($id <= 0) {
            return 'Escolha o closer.';
        }
        if ($id === $liderVendedorId) {
            return 'Escolha um closer da equipe que não seja você.';
        }
        foreach ($this->vendedores->doTime($equipeId) as $v) {
            if ($v['id'] === $id) {
                return null;
            }
        }
        return 'Escolha um closer da sua equipe.';
    }

    /**
     * Data do registro: vazia = hoje; senão, válida, não no futuro e no máximo 1 ano para trás. Devolve a data ou a mensagem de erro.
     * @param mixed $valor
     * @return array{0: string}|string
     */
    protected function dataDoRegistro($valor)
    {
        if ($valor === null || $valor === '') {
            return [Relogio::hoje()];
        }
        $d = RegistroValidador::data($valor);
        if ($d === null) {
            return 'Informe uma data válida.';
        }
        if ($d > Relogio::hoje()) {
            return 'A data não pode estar no futuro.';
        }
        if (!RegistroValidador::naJanela($d, 1, 0)) {
            return 'A data é antiga demais (mais de 1 ano).';
        }
        return [$d];
    }

    protected function agora(): string
    {
        return Relogio::agora()->format('Y-m-d H:i:s');
    }

    /** @param array<int, array<string, mixed>> $linhas @return array<int, string> id => nome dos closers citados nas linhas */
    protected function nomesDosCloseres(array $linhas): array
    {
        return $this->vendedores->nomes(array_map(static fn(array $r): int => (int) $r['closer_vendedor_id'], $linhas));
    }
}
