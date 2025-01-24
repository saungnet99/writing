<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Api\Library;

use Easy\Router\Attributes\Path;
use Presentation\RequestHandlers\Api\Api;

#[Path('/library/[images|documents|code-documents|transcriptions|speeches|conversations|isolated-voices|classifications|compositions:type]?')]
abstract class LibraryApi extends Api {}
