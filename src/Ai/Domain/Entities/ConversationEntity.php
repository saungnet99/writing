<?php

declare(strict_types=1);

namespace Ai\Domain\Entities;

use Ai\Domain\ValueObjects\Model;
use Ai\Domain\Entities\MessageEntity;
use Ai\Domain\Exceptions\MessageNotFoundException;
use Billing\Domain\ValueObjects\CreditCount;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use Override;
use Shared\Domain\ValueObjects\Id;
use Traversable;
use User\Domain\Entities\UserEntity;
use Workspace\Domain\Entities\WorkspaceEntity;

#[ORM\Entity]
class ConversationEntity extends AbstractLibraryItemEntity
{
    /** @var Collection<int,MessageEntity> */
    #[ORM\OneToMany(targetEntity: MessageEntity::class, mappedBy: 'conversation', cascade: ['persist', 'remove'])]
    private Collection $messages;

    public function __construct(
        WorkspaceEntity $workspace,
        UserEntity $user
    ) {
        parent::__construct(
            $workspace,
            $user,
            new Model(),
        );

        $this->messages = new ArrayCollection();
    }

    public function addCost(CreditCount $cost): self
    {
        $this->cost = new CreditCount(
            (float) $this->cost->value + (float) $cost->value
        );

        return $this;
    }

    public function addMessage(MessageEntity $message): self
    {
        $this->messages->add($message);

        $this->cost = new CreditCount(
            (float) $this->cost->value + (float) $message->getCost()->value
        );

        return $this;
    }

    /**
     * @return Traversable<MessageEntity>
     * @throws Exception
     */
    public function getMessages(): Traversable
    {
        yield from $this->messages->getIterator();
    }

    public function findMessage(Id|MessageEntity $id): MessageEntity
    {
        if ($id instanceof MessageEntity) {
            $id = $id->getId();
        }

        /** @var MessageEntity */
        foreach ($this->messages as $message) {
            if ($message->getId()->equals($id)) {
                return $message;
            }
        }

        throw new MessageNotFoundException($id);
    }

    public function getLastMessage(): ?MessageEntity
    {
        return  $this->messages->last() ?: null;
    }

    #[Override]
    public function getFiles(): Traversable
    {
        foreach ($this->messages as $message) {
            yield from $message->getFiles();
        }
    }
}
