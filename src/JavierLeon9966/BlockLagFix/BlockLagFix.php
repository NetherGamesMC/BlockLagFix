<?php

declare(strict_types = 1);

namespace JavierLeon9966\BlockLagFix;

use muqsit\simplepackethandler\interceptor\IPacketInterceptor;
use muqsit\simplepackethandler\SimplePacketHandler;

use pocketmine\block\Block;
use pocketmine\block\tile\Spawnable;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use function count;

final class BlockLagFix extends PluginBase{

	private IPacketInterceptor $handler;

	/** @phpstan-var \Closure(BlockActorDataPacket, NetworkSession): bool */
	private \Closure $handleBlockActorData;

	/** @phpstan-var \Closure(UpdateBlockPacket, NetworkSession): bool */
	private \Closure $handleUpdateBlock;
	private ?Player $lastPlayer = null;

	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $oldBlocksFullId = [];

	/**
	 * @var CacheableNbt[]
	 * @phpstan-var array<int, CacheableNbt<CompoundTag>>
	 */
	private array $oldTilesSerializedCompound = [];

	public function onEnable(): void{
		$this->handler = SimplePacketHandler::createInterceptor($this, EventPriority::HIGHEST);

		$this->handleUpdateBlock = function(UpdateBlockPacket $packet, NetworkSession $target): bool{
			if($target->getPlayer() !== $this->lastPlayer){
				return true;
			}
			$blockHash = World::blockHash($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
			if(!isset($this->oldBlocksFullId[$blockHash])){
				return true;
			}
			if(TypeConverter::getInstance($target->getProtocolId())->getBlockTranslator()->internalIdToNetworkId($this->oldBlocksFullId[$blockHash]) !== $packet->blockRuntimeId){
				return true;
			}
			unset($this->oldBlocksFullId[$blockHash]);
			if(count($this->oldBlocksFullId) === 0){
				if(count($this->oldTilesSerializedCompound) === 0){
					$this->lastPlayer = null;
				}
				$this->handler->unregisterOutgoingInterceptor($this->handleUpdateBlock);
			}
			return false;
		};
		$this->handleBlockActorData = function(BlockActorDataPacket $packet, NetworkSession $target): bool{
			if($target->getPlayer() !== $this->lastPlayer){
				return true;
			}
			$blockHash = World::blockHash($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
			if($packet->nbt !== ($this->oldTilesSerializedCompound[$blockHash] ?? null)){
				return true;
			}
			unset($this->oldTilesSerializedCompound[$blockHash]);
			if(count($this->oldTilesSerializedCompound) === 0){
				if(count($this->oldBlocksFullId) === 0){
					$this->lastPlayer = null;
				}
				$this->handler->unregisterOutgoingInterceptor($this->handleBlockActorData);
			}
			return false;
		};
		$this->getServer()->getPluginManager()->registerEvent(PlayerInteractEvent::class, function(PlayerInteractEvent $event): void{
			$item = $event->getItem();
			if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK || $item->isNull() || !$item->canBePlaced() || $this->hasOtherEntityInside($event)){
				return;
			}

			$player = $event->getPlayer();
			$this->lastPlayer = $player;
			$clickedBlock = $event->getBlock();
			$replaceBlock = $clickedBlock->getSide($event->getFace());
			$this->oldBlocksFullId = [];
			$this->oldTilesSerializedCompound = [];
			$saveOldBlock = function(Block $block) use ($player): void{
				$pos = $block->getPosition();
				$posIndex = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
				$this->oldBlocksFullId[$posIndex] = $block->getStateId();
				$tile = $pos->getWorld()->getTileAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
				if($tile instanceof Spawnable){
					$this->oldTilesSerializedCompound[$posIndex] = $tile->getSerializedSpawnCompound($player->getNetworkSession()->getProtocolId());
				}
			};
			foreach($clickedBlock->getAllSides() as $block){
				$saveOldBlock($block);
			}
			foreach($replaceBlock->getAllSides() as $block){
				$saveOldBlock($block);
			}
			$this->handler->interceptOutgoing($this->handleUpdateBlock);
			$this->handler->interceptOutgoing($this->handleBlockActorData);
		}, EventPriority::MONITOR, $this);
		$this->getServer()->getPluginManager()->registerEvent(BlockPlaceEvent::class, function(): void{
			$this->oldBlocksFullId = [];
			$this->oldTilesSerializedCompound = [];
			$this->lastPlayer = null;
			$this->handler->unregisterOutgoingInterceptor($this->handleUpdateBlock);
			$this->handler->unregisterOutgoingInterceptor($this->handleBlockActorData);
		}, EventPriority::MONITOR, $this, true);
	}

	private function hasOtherEntityInside(PlayerInteractEvent $event): bool
	{
		$item = $event->getItem();
		$face = $event->getFace();
		$blockClicked = $event->getBlock();
		$blockReplace = $blockClicked->getSide($event->getFace());
		$player = $event->getPlayer();
		$world = $player->getWorld();
		$clickVector = $event->getTouchVector();

		$hand = $item->getBlock($face);
		// @phpstan-ignore-next-line
		$hand->position($world, $blockReplace->getPosition()->x, $blockReplace->getPosition()->y, $blockReplace->getPosition()->z);

		if($hand->canBePlacedAt($blockClicked, $clickVector, $face, true)) {
			$blockReplace = $blockClicked;
			//TODO: while this mimics the vanilla behaviour with replaceable blocks, we should really pass some other
			//value like NULL and let place() deal with it. This will look like a bug to anyone who doesn't know about
			//the vanilla behaviour.
			$face = Facing::UP;
			// @phpstan-ignore-next-line
			$hand->position($world, $blockReplace->getPosition()->x, $blockReplace->getPosition()->y, $blockReplace->getPosition()->z);
		}

		$tx = new BlockTransaction($world);
		if(!$hand->place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player)){
			return false; // just in case
		}

		// TODO: this is a hack to prevent block placement when another entity is inside, since this caused ghost blocks
		foreach($tx->getBlocks() as [$x, $y, $z, $block]){
			$block->position($world, $x, $y, $z);
			foreach($block->getCollisionBoxes() as $collisionBox){
				if(count($collidingEntities = $world->getCollidingEntities($collisionBox)) > 0){
					if ($collidingEntities !== [$player]){
						return true;
					}
				}
			}
		}

		return false;
	}
}
