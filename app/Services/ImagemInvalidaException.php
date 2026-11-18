<?php
declare(strict_types=1);

namespace App\Services;

use Exception;

/** A foto enviada não pode ser usada (grande demais, não é imagem, servidor sem GD...). A mensagem é segura para mostrar ao admin. */
final class ImagemInvalidaException extends Exception
{
}
