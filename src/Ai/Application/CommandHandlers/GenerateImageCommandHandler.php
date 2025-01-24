<?php

declare(strict_types=1);

namespace Ai\Application\CommandHandlers;

use Ai\Application\Commands\GenerateImageCommand;
use Ai\Domain\Entities\ImageEntity;
use Ai\Domain\Exceptions\InsufficientCreditsException;
use Ai\Domain\Exceptions\ModelNotAccessibleException;
use Ai\Domain\Image\ImageServiceInterface;
use Ai\Domain\Repositories\LibraryItemRepositoryInterface;
use Ai\Domain\Services\AiServiceFactoryInterface;
use Billing\Domain\Events\CreditUsageEvent;
use File\Domain\Entities\ImageFileEntity;
use File\Domain\ValueObjects\BlurHash;
use File\Domain\ValueObjects\Height;
use File\Domain\ValueObjects\ObjectKey;
use File\Domain\ValueObjects\Size;
use File\Domain\ValueObjects\Storage;
use File\Domain\ValueObjects\Url;
use File\Domain\ValueObjects\Width;
use GdImage;
use kornrunner\Blurhash\Blurhash as BlurhashHelper;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\Uuid;
use Shared\Infrastructure\FileSystem\CdnInterface;
use User\Domain\Entities\UserEntity;
use User\Domain\Repositories\UserRepositoryInterface;
use Workspace\Domain\Entities\WorkspaceEntity;
use Workspace\Domain\Repositories\WorkspaceRepositoryInterface;

class GenerateImageCommandHandler
{
    public function __construct(
        private AiServiceFactoryInterface $factory,
        private WorkspaceRepositoryInterface $wsRepo,
        private UserRepositoryInterface $userRepo,
        private LibraryItemRepositoryInterface $repo,
        private CdnInterface $cdn,
        private EventDispatcherInterface $dispatcher,
    ) {}

    public function handle(GenerateImageCommand $cmd): ImageEntity
    {
        $ws = $cmd->workspace instanceof WorkspaceEntity
            ? $cmd->workspace
            : $this->wsRepo->ofId($cmd->workspace);

        $user = $cmd->user instanceof UserEntity
            ? $cmd->user
            : $this->userRepo->ofId($cmd->user);

        if (
            !is_null($ws->getTotalCreditCount()->value)
            && (float) $ws->getTotalCreditCount()->value <= 0
        ) {
            throw new InsufficientCreditsException();
        }

        $sub = $ws->getSubscription();
        $models = $sub ? $sub->getPlan()->getConfig()->models : [];

        if (!isset($models[$cmd->model->value]) || !$models[$cmd->model->value]) {
            throw new ModelNotAccessibleException($cmd->model);
        }

        $service = $this->factory->create(
            ImageServiceInterface::class,
            $cmd->model
        );

        $resp = $service->generateImage(
            $cmd->model,
            $cmd->width,
            $cmd->height,
            $cmd->params
        );

        $img = $resp->image;
        $width = imagesx($img);
        $height = imagesy($img);

        // Convert image to PNG
        ob_start();
        imagepng($img);
        $content = ob_get_contents(); // read from buffer
        ob_end_clean();

        // Save image to CDN
        $name = Uuid::uuid4()->toString() . '.png';
        $this->cdn->write($name, $content);

        $file = new ImageFileEntity(
            new Storage($this->cdn->getAdapterLookupKey()),
            new ObjectKey($name),
            new Url($this->cdn->getUrl($name)),
            new Size(strlen($content)),
            new Width($width),
            new Height($height),
            new BlurHash($this->generateBlurHash($img, $width, $height)),
        );

        $entity = new ImageEntity(
            $ws,
            $user,
            $file,
            $cmd->model,
            $resp->params,
            $resp->cost
        );

        $this->repo->add($entity);

        // Deduct credit from workspace
        $ws->deductCredit($resp->cost);

        // Dispatch event
        $event = new CreditUsageEvent($ws, $resp->cost);
        $this->dispatcher->dispatch($event);

        return $entity;
    }

    private function generateBlurHash(GdImage $image, int $width, int $height): string
    {
        if ($width > 64) {
            $height = (int) (64 / $width * $height);
            $width = 64;
            $image = imagescale($image, $width);
        }

        $pixels = [];
        for ($y = 0; $y < $height; ++$y) {
            $row = [];
            for ($x = 0; $x < $width; ++$x) {
                $index = imagecolorat($image, $x, $y);
                $colors = imagecolorsforindex($image, $index);

                $row[] = [$colors['red'], $colors['green'], $colors['blue']];
            }
            $pixels[] = $row;
        }

        $components_x = 4;
        $components_y = 3;
        return BlurhashHelper::encode($pixels, $components_x, $components_y);
    }
}
