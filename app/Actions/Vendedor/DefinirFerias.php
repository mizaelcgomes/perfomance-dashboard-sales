<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\VendedorRepository;
use App\Services\ContratoService;

/**
 * Caso de uso: definir o período de férias (início obrigatório; fim vazio = sem data de retorno). Início hoje ou no passado: entra em férias
 * já (suspended_at). Início futuro: fica agendado, e o sincronizador de férias suspende no dia certo (e reativa no dia seguinte ao fim).
 */
final class DefinirFerias
{
    public function __construct(private VendedorRepository $vendedores)
    {
    }

    /** @param mixed $id @param mixed $inicio @param mixed $fim */
    public function executar($id, $inicio, $fim): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        $inicio = trim(is_scalar($inicio) ? (string) $inicio : '');
        $fim = trim(is_scalar($fim) ? (string) $fim : '');
        if (!$id || !ContratoService::dataValida($inicio) || ($fim !== '' && !ContratoService::dataValida($fim))) {
            return Resultado::falha('Datas inválidas');
        }
        if ($fim !== '' && $fim < $inicio) {
            return Resultado::falha('A data final não pode ser anterior à inicial');
        }
        $hoje = Relogio::hoje();
        if ($fim !== '' && $fim < $hoje) {
            return Resultado::falha('A data final já passou');
        }
        if (!$this->vendedores->ativoExiste($id)) {
            return Resultado::falha('Closer não encontrado');
        }
        $agora = $inicio <= $hoje;
        $this->vendedores->definirFerias($id, $inicio, $fim !== '' ? $fim : null, $agora);
        $br = static fn(string $d): string => substr($d, 8, 2) . '/' . substr($d, 5, 2);
        $mensagem = $agora
            ? ($fim !== '' ? 'Férias registradas — fora do ranking até ' . $br($fim) : 'Closer em férias — removido do ranking')
            : 'Férias agendadas a partir de ' . $br($inicio);
        return Resultado::ok([], $mensagem);
    }
}
