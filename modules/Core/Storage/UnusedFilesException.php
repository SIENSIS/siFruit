<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

use RuntimeException;

/**
 * A scan of unused files failed (D-55-01): a `FileReferenceProvider` threw, is missing or
 * declared a folder outside the module spaces. Nothing is listed or deleted; the message is
 * shown to the administrator (no secrets).
 */
final class UnusedFilesException extends RuntimeException
{
}
