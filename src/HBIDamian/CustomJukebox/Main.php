<?php
declare(strict_types=1);

namespace HBIDamian\CustomJukebox;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\player\Player;
use pocketmine\utils\Config;

use pocketmine\item\StringToItemParser;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\resourcepacks\ZippedResourcePack;
use Ramsey\Uuid\Uuid;

final class Main extends PluginBase implements Listener {

    private Config $config;
    private string $audioDir;
    private string $packDir;
    private string $zipPack;
    private array $soundNames = []; // map "track1" => "customjukebox.track1"

    public function onEnable() : void {
        // Register events
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Ensure config exists
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);

        // Prepare directories
        $this->audioDir = $this->getDataFolder()."audio/";
        @mkdir($this->audioDir);
        
        $this->packDir = $this->getDataFolder()."packTemp/";
        @mkdir($this->packDir);

        $this->zipPack = $this->getDataFolder()."CustomJukebox_resource_pack.zip";

        // Build the dynamic resource pack
        $this->buildResourcePack();

        // Register it with PMMP's ResourcePackManager
        $manager = $this->getServer()->getResourcePackManager();
        if(file_exists($this->zipPack)){
            $resourcePack = new ZippedResourcePack($this->zipPack);
            $manager->setResourceStack([$resourcePack]);
            // Force the client to accept resource packs or they can't join
            $manager->setResourcePacksRequired(true);
            $this->getLogger()->info("[CustomJukebox] Dynamic resource pack loaded and forced.");
        } else {
            $this->getLogger()->warning("[CustomJukebox] No resource pack zip found, custom audio won't work!");
        }
    }

    /**
     * Build the resource pack in Bedrock "sound_definitions.json" format.
     */
    private function buildResourcePack(): void {
        $this->getLogger()->info("[CustomJukebox] Building resource pack...");

        // Clear out old folder
        $this->deleteRecursive($this->packDir);
        @mkdir($this->packDir);
        @mkdir($this->packDir."sounds/");

        // Copy a pack icon if you have it. Otherwise create a blank one.
        if(file_exists($this->getFile()."resources/pack_icon.png")){
            @copy($this->getFile()."resources/pack_icon.png", $this->packDir."pack_icon.png");
        } else {
            file_put_contents($this->packDir."pack_icon.png", "");
        }

        // Gather .ogg files from plugin_data/CustomJukebox/audio
        $files = glob($this->audioDir."*.ogg");

        $soundDefinitions = [
            "format_version" => "1.14.0",
            "sound_definitions" => []
        ];

        foreach($files as $filepath){
            $base = pathinfo($filepath, PATHINFO_FILENAME); // e.g. track1
            $dest = $this->packDir."sounds/".basename($filepath); // e.g. sounds/track1.ogg
            @copy($filepath, $dest);

            // e.g. customjukebox.track1
            $soundEventName = "customjukebox." . $base;
            // store it in $soundNames so we can give items that reference it
            $this->soundNames[$base] = $soundEventName;

            // In Bedrock, you typically omit the .ogg extension in the array:
            // "sounds": ["sounds/track1"]
            $soundDefinitions["sound_definitions"][$soundEventName] = [
                "category" => "records",
                "sounds" => [
                    "sounds/" . $base // no file extension
                ]
            ];
        }

        // Write out the sound_definitions.json
        file_put_contents(
            $this->packDir."sounds/sound_definitions.json",
            json_encode($soundDefinitions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Build manifest.json
        $manifest = [
            "format_version" => 2,
            "header" => [
                "name" => "CustomJukebox Pack",
                "uuid" => Uuid::uuid4()->toString(),
                "version" => [1, 0, 0],
                "min_engine_version" => [1, 19, 0]
            ],
            "modules" => [
                [
                    "type" => "resources",
                    "uuid" => Uuid::uuid4()->toString(),
                    "version" => [1, 0, 0]
                ]
            ]
        ];
        file_put_contents(
            $this->packDir."manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Finally, zip the packTemp folder
        $this->zipFolder($this->packDir, $this->zipPack);
        $this->getLogger()->info("[CustomJukebox] Resource pack created at ".$this->zipPack);
    }

    /**
     * Delete a folder recursively.
     */
    private function deleteRecursive(string $dir): void {
        if(!is_dir($dir)) return;
        $items = scandir($dir);
        if(!$items) return;
        foreach($items as $item){
            if($item === "." || $item === "..") continue;
            $path = $dir.$item;
            if(is_dir($path)){
                $this->deleteRecursive($path."/");
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    /**
     * Zip an entire folder into a single zip file.
     */
    private function zipFolder(string $source, string $destination): void {
        $rootPath = realpath($source);
        $zip = new \ZipArchive();
        $zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach($files as $file){
            if(!$file->isDir()){
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
    }

    /**
     * Give players an example item on join that triggers one of the custom sounds.
     */
    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();

        if(count($this->soundNames) === 0){
            $this->getLogger()->warning("[CustomJukebox] No .ogg files found. No items given.");
            return;
        }

        // Take the first track from the array
        $firstBase = array_key_first($this->soundNames);
        $soundEventName = $this->soundNames[$firstBase];

        // Give the player a "TestAudio Stick"
        $item = StringToItemParser::getInstance()->parse("stick") ?? null;
        if($item === null){
            $this->getLogger()->warning("Failed to parse 'stick' as an item!");
            return;
        }

        $item->setCustomName("TestAudio Stick");
        $nbt = $item->getNamedTag();
        $nbt->setString("soundEvent", $soundEventName);
        $item->setNamedTag($nbt);

        $player->getInventory()->addItem($item);
        $player->sendMessage("Given a 'TestAudio Stick' to play sound: ".$soundEventName);
    }

    /**
     * When a player interacts with the "TestAudio Stick," play the stored sound event.
     */
    public function onInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();
        $soundEvent = $item->getNamedTag()->getString("soundEvent", "");
        if($soundEvent !== ""){
            $event->cancel();
            $this->playSound($player, $soundEvent);
        }
    }

    private function playSound(Player $player, string $soundEvent) : void {
        $this->getLogger()->info("[CustomJukebox] Attempting to play '$soundEvent' for ".$player->getName());
        $pos = $player->getPosition();

        // Use the event name that was stored (not always "customjukebox.track1")
        $pk = PlaySoundPacket::create(
            $soundEvent,
            $pos->getX(),
            $pos->getY(),
            $pos->getZ(),
            1.0,  // volume
            1.0   // pitch
        );
        $player->getNetworkSession()->sendDataPacket($pk);
    }
}
