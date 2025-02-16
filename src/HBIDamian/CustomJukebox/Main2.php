<?php
declare(strict_types=1);

namespace HBIDamian\CustomJukebox;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\block\Jukebox;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\scheduler\ClosureTask;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\world\World;
use pocketmine\math\Vector3;
use Ramsey\Uuid\Uuid;
use pocketmine\inventory\CreativeInventory;

/**
 * Custom Jukebox plugin that:
 *  - Coexists with vanilla Jukebox logic (normal discs still work),
 *  - Inserts/ejects custom discs (no double-play),
 *  - Automatically stops custom track on ejection or block break,
 *  - Auto-ejects custom disc after the track finishes.
 */
final class Main extends PluginBase implements Listener {

    private Config $config;

    /**
     * Each record = [
     *   "name"            => string,  // e.g. "NGGYU"
     *   "file"            => string,  // e.g. "track1.ogg"
     *   "length"          => int,     // length in seconds (auto-eject delay)
     *   "item_identifier" => string,  // e.g. "customjukebox:disc_0"
     *   "sound_event"     => string,  // e.g. "customjukebox.track1"
     *   "lore"            => string[]
     * ]
     *
     * @var array<int, array<string, mixed>>
     */
    private array $records = [];

    private string $audioDir;
    private string $packDir;
    private string $zipPack;

    /**
     * Active jukeboxes storing custom discs:
     *
     * key = worldName:x:y:z
     * value = [
     *   "sound_event"  => string,
     *   "task_handler" => int|null    // scheduled auto-eject task handler (so we can cancel on manual eject/break)
     * ]
     */
    private array $activeJukeboxes = [];

    /**
     * onLoad is called very early. We use it here to load the config and records,
     * then register our custom discs into the CreativeInventory.
     */
    public function onLoad(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->loadRecords();
        // Register custom discs into the creative inventory.
        foreach ($this->records as $r) {
            $disc = StringToItemParser::getInstance()->parse($r["item_identifier"]);
            if ($disc === null) {
                $this->getLogger()->warning("Failed to parse item '{$r["item_identifier"]}' during creative registration!");
                continue;
            }
            $disc->setCustomName($r["name"]);
            $disc->setLore($r["lore"]);
            CreativeInventory::getInstance()->add($disc);
        }
    }

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Prepare directories
        $this->audioDir = $this->getDataFolder() . "audio/";
        @mkdir($this->audioDir);
        $this->packDir = $this->getDataFolder() . "packTemp/";
        @mkdir($this->packDir);
        $this->zipPack = $this->getDataFolder() . "CustomJukebox_resource_pack.zip";

        // Build & register resource pack
        $this->buildResourcePack();
        $rpMan = $this->getServer()->getResourcePackManager();
        if (file_exists($this->zipPack)) {
            $rpMan->setResourceStack([new ZippedResourcePack($this->zipPack)]);
            $rpMan->setResourcePacksRequired(true);
            $this->getLogger()->info("§a[CustomJukebox] Resource pack built & forced.");
        } else {
            $this->getLogger()->warning("§c[CustomJukebox] No resource pack zip found, custom discs won't work!");
        }

        // For online players in creative mode, force a refresh of their inventory.
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($player->isCreative()) {
                $player->getInventory()->setContents($player->getInventory()->getContents());
            }
        }
    }

    /**
     * Parse the config for custom_records.
     */
    private function loadRecords(): void {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = $this->config->get("custom_records", []);
        if (count($raw) === 0) {
            $this->getLogger()->warning("No 'custom_records' found in config.yml!");
            return;
        }
        $i = 0;
        foreach ($raw as $entry) {
            $name   = (string)($entry["name"] ?? "Disc #$i");
            $file   = (string)($entry["file"] ?? "");
            $length = (int)($entry["length"] ?? 120); // default 120 seconds
            if ($file === "") {
                $this->getLogger()->warning("Record #$i has no 'file' defined => skipping");
                continue;
            }
            $base = pathinfo($file, PATHINFO_FILENAME);
            // Define item identifier e.g. "customjukebox:disc_0"
            $itemId  = "customjukebox:" . $i;
            // Define sound event e.g. "customjukebox.track1"
            $soundEv = "customjukebox." . $base;
            // Default lore
            $lore = ["Play: " . $file];
            $this->records[$i] = [
                "name"            => $name,
                "file"            => $file,
                "length"          => $length,
                "item_identifier" => $itemId,
                "sound_event"     => $soundEv,
                "lore"            => $lore
            ];
            $i++;
        }
    }

    /**
     * Build & zip the resource pack with:
     * - sounds/sound_definitions.json
     * - items/*.json
     * - textures/item_texture.json
     * - manifest.json
     */
    private function buildResourcePack(): void {
        $this->getLogger()->info("[CustomJukebox] Building resource pack...");

        // Clear old pack folder
        $this->deleteRecursive($this->packDir);
        @mkdir($this->packDir);
        @mkdir($this->packDir . "sounds/");
        @mkdir($this->packDir . "items/");
        @mkdir($this->packDir . "textures/");
        @mkdir($this->packDir . "textures/items/");

        // Copy pack icon if exists
        $iconPath = $this->getFile() . "resources/pack_icon.png";
        if (file_exists($iconPath)) {
            @copy($iconPath, $this->packDir . "pack_icon.png");
        } else {
            file_put_contents($this->packDir . "pack_icon.png", "");
        }

        // Prepare sound definitions and item texture data
        $soundDefs = [
            "format_version"    => "1.14.0",
            "sound_definitions" => []
        ];
        $itemTex = [
            "resource_pack_name" => "CustomJukeboxRP",
            "texture_name"       => "atlas.items",
            "texture_data"       => []
        ];

        // Use placeholder icon for discs if not provided
        $placeholder = $this->getFile() . "resources/disc_placeholder.png";
        if (!file_exists($placeholder)) {
            $img = imagecreatetruecolor(16, 16);
            $magenta = imagecolorallocate($img, 255, 0, 255);
            imagefilledrectangle($img, 0, 0, 15, 15, $magenta);
            imagepng($img, $placeholder);
            imagedestroy($img);
        }

        // Process each custom disc record
        foreach ($this->records as $i => $r) {
            $srcAudio = $this->audioDir . $r["file"];
            if (!file_exists($srcAudio)) {
                $this->getLogger()->warning("Audio '{$r["file"]}' not found in audio/ => skipping");
                continue;
            }
            $destAudio = $this->packDir . "sounds/" . basename($srcAudio);
            @copy($srcAudio, $destAudio);

            $baseNoExt = pathinfo($r["file"], PATHINFO_FILENAME);
            $soundDefs["sound_definitions"][$r["sound_event"]] = [
                "category" => "records",
                "sounds"   => ["sounds/" . $baseNoExt]
            ];

            $discKey = $i;
            $itemJson = [
                "format_version"  => "1.16.100",
                "minecraft:item"  => [
                    "description" => [
                        "identifier"        => $r["item_identifier"],
                        "category"          => "Items",
                        "creative_category" => [
                            "parent" => "itemGroup.name.record"
                        ]
                    ],
                    "components"  => [
                        "minecraft:icon"         => $discKey,
                        "minecraft:display_name" => ["value" => $r["name"]],
                        "minecraft:max_stack_size" => 1
                    ]
                ]
            ];
            file_put_contents(
                $this->packDir . "items/$discKey.json",
                json_encode($itemJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $itemTex["texture_data"][$discKey] = [
                "textures" => "textures/items/" . $discKey
            ];
            @copy($placeholder, $this->packDir . "textures/items/$discKey.png");
        }

        file_put_contents(
            $this->packDir . "sounds/sound_definitions.json",
            json_encode($soundDefs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            $this->packDir . "textures/item_texture.json",
            json_encode($itemTex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $manifest = [
            "format_version" => 2,
            "header"         => [
                "name"               => "CustomJukebox Pack",
                "uuid"               => Uuid::uuid4()->toString(),
                "version"            => [1, 0, 0],
                "min_engine_version" => [1, 19, 0]
            ],
            "modules" => [
                [
                    "type"    => "resources",
                    "uuid"    => Uuid::uuid4()->toString(),
                    "version" => [1, 0, 0]
                ]
            ]
        ];
        file_put_contents(
            $this->packDir . "manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->zipFolder($this->packDir, $this->zipPack);
        $this->getLogger()->info("[CustomJukebox] Resource pack created: " . $this->zipPack);
    }

    /**
     * Give each player all custom discs on join (for testing) by adding them to their inventory.
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        foreach ($this->records as $r) {
            $disc = StringToItemParser::getInstance()->parse($r["item_identifier"]);
            if ($disc === null) {
                $this->getLogger()->warning("Failed to parse item '{$r["item_identifier"]}' on join!");
                continue;
            }
            $disc->setCustomName($r["name"]);
            $disc->setLore($r["lore"]);
            $player->getInventory()->addItem($disc);
        }
        $player->sendMessage("§eGiven all custom discs for testing!");
    }

    /**
     * Core logic: if the item is a custom disc, cancel default Jukebox behavior
     * and do your own insertion/ejection logic. Otherwise, let vanilla handle it.
     */
    public function onBlockInteract(PlayerInteractEvent $event): void {
        $block = $event->getBlock();
        if (!$block instanceof Jukebox) {
            return;
        }

        $key = $this->getKey($block);
        if (isset($this->activeJukeboxes[$key])) {
            $event->cancel();
            $info = $this->activeJukeboxes[$key];
            $this->stopSound($block->getPosition()->getWorld(), $info["sound_event"]);
            if (isset($info["task_handler"])) {
                $info["task_handler"]->cancel();
            }
            unset($this->activeJukeboxes[$key]);
            $disc = $this->createDiscFromSound($info["sound_event"]);
            if ($disc !== null) {
                $block->getPosition()->getWorld()->dropItem(
                    $block->getPosition()->add(0.5, 1, 0.5),
                    $disc
                );
            }
            $event->getPlayer()->sendMessage("§7Custom disc ejected.");
            return;
        }

        $item = $event->getItem();
        $recordIndex = $this->getRecordIndex($item);
        if ($recordIndex === null) {
            return;
        }

        $event->cancel();
        $rec = $this->records[$recordIndex];
        $player = $event->getPlayer();
        $count = $item->getCount();
        if ($count > 1) {
            $item->setCount($count - 1);
            $player->getInventory()->setItemInHand($item);
        } else {
            $player->getInventory()->setItemInHand(VanillaItems::AIR());
        }

        $this->activeJukeboxes[$key] = [
            "sound_event"  => $rec["sound_event"],
            "task_handler" => null
        ];

        $pos = $block->getPosition();
        $this->playSound($pos->getWorld(), $pos->x + 0.5, $pos->y + 0.5, $pos->z + 0.5, $rec["sound_event"]);
        $player->sendMessage("§aNow playing: " . $rec["name"]);

        $ticks = max(20, $rec["length"] * 20);
        $task = new ClosureTask(function() use ($key, $pos): void {
            if (isset($this->activeJukeboxes[$key])) {
                $info = $this->activeJukeboxes[$key];
                $this->stopSound($pos->getWorld(), $info["sound_event"]);
                unset($this->activeJukeboxes[$key]);
                $disc = $this->createDiscFromSound($info["sound_event"]);
                if ($disc !== null) {
                    $pos->getWorld()->dropItem($pos->add(0.5, 1, 0.5), $disc);
                }
            }
        });
        $taskHandler = $this->getScheduler()->scheduleDelayedTask($task, $ticks);
        $this->activeJukeboxes[$key]["task_handler"] = $taskHandler;
    }

    /**
     * Stop the given sound by sending volume=0.0 to all players in the world.
     */
    private function stopSound(World $world, string $soundEvent): void {
        $pk = PlaySoundPacket::create($soundEvent, 0, 0, 0, 0.0, 0.0);
        foreach ($world->getPlayers() as $p) {
            $p->getNetworkSession()->sendDataPacket($pk);
        }
    }

    /**
     * Play the given sound at X,Y,Z in ~16-block radius.
     */
    private function playSound(World $world, float $x, float $y, float $z, string $soundEvent): void {
        $pk = PlaySoundPacket::create($soundEvent, $x, $y, $z, 1.0, 1.0);
        foreach ($world->getPlayers() as $p) {
            if ($p->getPosition()->distanceSquared(new Vector3($x, $y, $z)) <= 256) {
                $p->getNetworkSession()->sendDataPacket($pk);
            }
        }
    }

    /**
     * Return which record index this item is, or null if it's not one of our custom discs.
     */
    private function getRecordIndex(Item $item): ?int {
        $customName = $item->getCustomName();
        if ($customName === "") {
            return null;
        }
        foreach ($this->records as $i => $r) {
            if ($r["name"] === $customName) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Create an Item from the stored sound_event (to drop/eject the disc).
     */
    private function createDiscFromSound(string $soundEvent): ?Item {
        foreach ($this->records as $r) {
            if ($r["sound_event"] === $soundEvent) {
                $disc = StringToItemParser::getInstance()->parse($r["item_identifier"]);
                if ($disc !== null) {
                    $disc->setCustomName($r["name"]);
                    $disc->setLore($r["lore"]);
                }
                return $disc;
            }
        }
        return null;
    }

    /**
     * Unique key "worldName:x:y:z" for an individual Jukebox block.
     */
    private function getKey(Jukebox $block): string {
        $pos = $block->getPosition();
        return $pos->getWorld()->getFolderName() . ":" . $pos->x . ":" . $pos->y . ":" . $pos->z;
    }

    /**
     * Recursively delete a folder.
     */
    private function deleteRecursive(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === "." || $file === "..") {
                continue;
            }
            $path = $dir . $file;
            if (is_dir($path)) {
                $this->deleteRecursive($path . "/");
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    /**
     * Zip an entire folder.
     */
    private function zipFolder(string $source, string $destination): void {
        $rootPath = realpath($source);
        $zip = new \ZipArchive();
        $zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relPath = substr($filePath, strlen($rootPath) + 1);
                $zip->addFile($filePath, $relPath);
            }
        }
        $zip->close();
    }
}
