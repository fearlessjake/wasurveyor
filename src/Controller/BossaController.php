<?php


namespace App\Controller;


use App\Entity\Alliance;
use App\Entity\Island;
use App\Entity\IslandTerritoryControl;
use App\Repository\AllianceRepository;
use App\Repository\IslandRepository;
use App\Repository\IslandTerritoryControlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use App\Utils\CustomEntityManager;
use FOS\RestBundle\Controller\Annotations\View;
use FOS\RestBundle\Controller\FOSRestController;
use Gedmo\Loggable\Entity\LogEntry;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Swagger\Annotations as SWG;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;
use OneSignal\Config;
use OneSignal\OneSignal;
use Http\Client\Common\HttpMethodsClient as HttpClient;
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

/**
 * Class ApiController
 * @package App\Controller
 * @Route("/api/bossa")
 */
class BossaController extends FOSRestController
{

	/**
	 * @var CacheManager
	 */
	protected $cacheManager;

	/**
	 * @var UploaderHelper
	 */
	protected $uploadHelper;

	/**
	 * @var IslandRepository
	 */
	protected $islandRepo;

	/**
	 * @var AllianceRepository
	 */
	protected $allianceRepo;

	/**
	 * @var IslandTerritoryControlRepository
	 */
	protected $islandTCRepo;

	/**
	 * @var EntityManagerInterface
	 */
    protected $entityManager;

    /**
     * @var CustomEntityManager
     */
    protected $customEntityManager;

	public function __construct(
		CacheManager $cacheManager,
		UploaderHelper $uploaderHelper,
		IslandRepository $islandRepo,
		IslandTerritoryControlRepository $islandTCRepo,
		AllianceRepository $allianceRepo,
        EntityManagerInterface $entityManager,
        CustomEntityManager $customEntityManager
	) {
		$this->cacheManager = $cacheManager;
		$this->uploadHelper = $uploaderHelper;
		$this->islandRepo = $islandRepo;
		$this->allianceRepo = $allianceRepo;
		$this->islandTCRepo = $islandTCRepo;
        $this->entityManager = $entityManager;
        $this->customEntityManager = $customEntityManager;
	}

	/**
	 * Post for Bossa tc info
	 *
	 * @Route("/island/info.{_format}", methods={"POST"}, defaults={ "_format": "json" })
	 * @SWG\Response(response=200, description="Post api for tc updates")
     * @SWG\Response(response=400, description="No region or island data provided")
	 * @SWG\Parameter( name="Authorization", in="header", required=true, type="string", default="Bearer TOKEN", description="Bossa Authorization key" )
	 * @SWG\Tag(name="Islands")
	 * @View()
	 */
	public function updateInfo(Request $request)
	{
		$logger = $this->get('monolog.logger.bossa');
        $logger->info(json_encode($request->request->all()));

        $uLogger = $this->get('monolog.logger.tc_updates');

		if (!$request->request->has('Region')) {
			return $this->view('no region provided', 400);
		}
		if (!$request->request->has('IslandDatas')) {
			return $this->view('no island data given', 400);
		}

		$islandDatas = $request->request->get('IslandDatas');
        $server = $request->request->get('Region');
		if($server == 'us_pve_01') {
			$server = IslandTerritoryControl::PVE;
		} else if($server == 'us_pvp_01') {
			$server = IslandTerritoryControl::PVP;
		} else {
			$server = IslandTerritoryControl::PTS;
		}
        $responses = [];
		// Loop over all islands that came back in api call
		foreach ($islandDatas as $key => $islandData) {
			// Get the id of the steam workshop id of an island and integer format
			$islandId = (int)explode("@", $key)[0];
			// Double check if it has the required fields
			if ($islandData['AllianceName'] && $islandData['TctName']) {

                /**
				 * @var Island $island
				 */
                $island = $this->islandRepo->findOneBy(["guid" => $islandId]);
                $id = '';
                if ($island) {
                    $id = $island->getId();
                }

                $callback = function() use ($id, $islandData, $islandId, $responses, $server, $uLogger) {
                    $island = $this->islandRepo->find($id, LockMode::PESSIMISTIC_WRITE);

                    // Set variables
                    $newAllianceName = $islandData['AllianceName'];
                    $newTowerName = $islandData['TctName'];

                    // filter out known islands missing from the workshop (I checked all of them)
                    //$missingFromWorkshop = ["1225029340", "1416243538", "1419417076", "1431299145", "1223923982", "1223292949", "1224180786", "1264742668", "1270483746", "1228734909", "1223311135", "1263728093", "1416236875"];

                    /**
                     * @var Island $island
                     */
                    $island = $this->islandRepo->findOneBy(["guid" => $islandId]);

                    // Check if island has the correct tier
                    if ($island && $island->getTier() > 2) {
                        //Get territory control, if it exists
                        $territoryControl = $this->islandTCRepo->findOneBy(['server'=>$server, 'island'=>$island]);
                        if(!$territoryControl) {
                            $territoryControl = new IslandTerritoryControl();
                            $territoryControl->setServer($server);
                            $territoryControl->setIsland($island);
                        }

                        // Store previous tower and alliance name for discord channel updates, even if nulled
                        $currentAllianceName = $territoryControl->getAllianceName();
                        // Get the tower name, if null returned, you get 'Unnamed' as string back
                        $currentTowerName = $territoryControl->getTowerName();

                        if ($newAllianceName === "Unclaimed" && $newTowerName === "None") { // this will ONLY be Unclaimed if there is no alliance, not unnamed, or none or something else. better to be specific
                            $territoryControl->setAlliance(null);
                            $territoryControl->setTowerName("None");
                            $uLogger->info("Island '".$island->getUsedName()."' with id '".$island->getGuid()."' changed from alliance '$currentAllianceName' to Unclaimed'");
                            $responses[] = "Island '".$island->getUsedName()."' with id '".$island->getGuid()."' changed from alliance '$currentAllianceName' to Unclaimed'";
                            if ($this->getPreviousAllianceName($territoryControl, true) !== "Unclaimed") {
                                $this->sendDiscordUpdate($territoryControl->getServer(), $island, $this->getPreviousAllianceName($territoryControl), "Unclaimed");
                                $this->sendOneSignalMessage($territoryControl->getServer(), $island, $this->getPreviousAllianceName($territoryControl), "Unclaimed");
                            }
                        }
                        else if ($currentAllianceName !== $newAllianceName) {
                            /**
                             * @var $alliance Alliance
                             */
                            $alliance = $this->allianceRepo->findOneBy(['name' => trim($newAllianceName)]);
                            if (!$alliance) {
                                $alliance = new Alliance();
                                $alliance->setName(trim($newAllianceName));
                            }
                            $territoryControl->setAlliance($alliance);
                            $territoryControl->setTowerName($newTowerName);
                            $uLogger->info("Island '".$island->getUsedName()."' with id '".$island->getGuid()."' changed from alliance '$currentAllianceName' to '".$territoryControl->getAllianceName()."'");
                            $responses[] = "Island '".$island->getUsedName()."' with id '".$island->getGuid()."' changed from alliance '$currentAllianceName' to '".$territoryControl->getAllianceName()."'";

                            $this->sendDiscordUpdate($territoryControl->getServer(), $island, $this->getPreviousAllianceName($territoryControl), $alliance);
                            $this->sendOneSignalMessage($territoryControl->getServer(), $island, $this->getPreviousAllianceName($territoryControl), $alliance);
                        }
                        else if ($currentTowerName !== $newTowerName) { // If tower name has changed
                            $territoryControl->setTowerName($newTowerName);
                            $uLogger->info("Island '".$island->getUsedName()."' with id '".$island->getGuid()."' changed from tower name '$currentTowerName' to '".$territoryControl->getTowerName()."'");
                            $responses[] = "Island '".$island->getUsedName()."' with id '".$island->getGuid()."' changed from tower name '$currentTowerName' to '".$territoryControl->getTowerName()."'";
                        }
                        else {
                            $responses[] = "Duplicate";
                        }
                        $this->customEntityManager->persist($territoryControl);
                        return $responses;
                    }
                    else if (!$island) {
                        $uLogger->warning($islandId." is an UNKNOWN ID");
                        $responses[] = $islandId." is an UNKNOWN ID";
                        return $responses;
                    }
                    else {
                        $responses[] = "Not a t3 or t4 island";
                        return $responses;
                    }
                };

                $responses = $this->customEntityManager->transactional($callback);
            }
            else {
                $responses[] = "Missing AllianceName or TctName";
            }
		}
		return $this->view($responses);
    }

    private function sendDiscordUpdate($server, $island, $oldAllianceName, $newAlliance)
    {
        $bossaPVETcChannel = $this->getParameter('bossa_pve_tc_channel');
        $bossaPVPTcChannel = $this->getParameter('bossa_pvp_tc_channel');
		$uLogger = $this->get('monolog.logger.tc_updates');

		$image = $island->getImages()->first();

        $url = $this->cacheManager->getBrowserPath($this->uploadHelper->asset($image, 'imageFile'), 'island_popup');

        if ($oldAllianceName === "Unclaimed") {
            $description = "**`".$newAlliance->getName()."`** has taken over ".$island->getUsedName();
        }
        else if ($newAlliance === "Unclaimed") {
            $description = "**`".$oldAllianceName."`** has lost their tower on ".$island->getUsedName();
        }
        else if ($oldAllianceName === $newAlliance->getName()) {
            $description = "**`".$newAlliance->getName()."`** has reclaimed ".$island->getUsedName();
        }
        else {
            $description = "**`".$newAlliance->getName()."`** has taken control of ".$island->getUsedName()." from **`".$oldAllianceName."`**";
        }

        $footer = null;
        if ($newAlliance === "Unclaimed" && $oldAllianceName !== "Unclaimed") { // when an alliance loses an island to Unclaimed
            $oldAlliance = $this->allianceRepo->findOneBy(["name" => $oldAllianceName]);
            $territories = $this->islandTCRepo->findBy(["alliance" => $oldAlliance]);
            if ($oldAlliance && $territories) {
                $count = $territories ? count($territories) - 1 : 0;
                $footer = $oldAllianceName." has " .$count." island".($count === 1 ? "" : "s")." now";
            }

        }
        else {
            $count = $newAlliance->getTerritories() ? count($newAlliance->getTerritories()) + 1 : 1;
            $footer = $newAlliance->getName()." has ".$count." island".($count === 1 ? "" : "s");
        }
        if(in_array($server, ['pvp','pve'])) {
	        $channel = $bossaPVPTcChannel;
        	if($server === IslandTerritoryControl::PVE) {
        		$channel = $bossaPVETcChannel;
	        }
	        $postBody = [
		        "embeds" => [
			        [
				        "title" => $island->getUsedName(),
				        "url" => "https://map.cardinalguild.com/" . $server . "/" . $island->getId(), // change pvp to server or make pts link to one of the modes
				        "type" => "rich",
				        "author" => [
					        "name" => strtoupper($server) . " server"
				        ],
				        "thumbnail" => [
					        "url" => $url
				        ],
				        "timestamp" => date('c'),
				        "color" => $island->getTier() === 4 ? hexdec('f7c38f') : hexdec('e3c9f9'),
				        "description" => $description,
				        "footer" => [
					        "text" => $footer
				        ]
			        ]
		        ]
	        ];

	        try {
		        $client = new \GuzzleHttp\Client(['headers' => ['Content-Type' => 'application/json']]);
		        $client->request('POST', $channel, ['json' => $postBody]);
	        } catch (\Exception $e) {
	        }
        }
    }

	private function sendOneSignalMessage($server, $island, $oldAllianceName, $newAlliance)
	{
		if($server !== IslandTerritoryControl::PVP) {
			return;
		}
		$image = $island->getImages()->first();

		$url = $this->cacheManager->getBrowserPath($this->uploadHelper->asset($image, 'imageFile'), 'island_popup');

		if ($oldAllianceName === "Unclaimed") {
			$title = strtoupper($server).": ".$newAlliance->getName()." has taken over ".$island->getUsedName()."";
		}
		else if ($newAlliance === "Unclaimed") {
			$title = strtoupper($server).": ".$oldAllianceName." has lost their tower on ".$island->getUsedName()."";
		}
		else if ($oldAllianceName === $newAlliance->getName()) {
			$title = strtoupper($server).": ".$newAlliance->getName()." has reclaimed ".$island->getUsedName()."";
		}
		else {
			$title = strtoupper($server).": ".$newAlliance->getName()." has taken control of ".$island->getUsedName()." from ".$oldAllianceName."";
		}

		$infoText = null;
		if ($newAlliance === "Unclaimed" && $oldAllianceName !== "Unclaimed") { // when an alliance loses an island to Unclaimed
			$oldAlliance = $this->allianceRepo->findOneBy(["name" => $oldAllianceName]);
			$territories = $this->islandTCRepo->findBy(["alliance" => $oldAlliance]);
			if ($oldAlliance && $territories) {
				$count = $territories ? count($territories) - 1 : 0;
				$infoText = $oldAllianceName." has " .$count." island".($count === 1 ? "" : "s")." now";
			}

		}
		else {
			$count = $newAlliance->getTerritories() ? count($newAlliance->getTerritories()) + 1 : 1;
			$infoText = $newAlliance->getName()." has ".$count." island".($count === 1 ? "" : "s");
		}
		$config = new Config();

		$config->setApplicationId($this->getParameter('onesignal_app_id'));
		$config->setApplicationAuthKey($this->getParameter('onesignal_app_auth_key'));
		$config->setUserAuthKey($this->getParameter('onesignal_user_auth_key'));

		$guzzleClient = new \GuzzleHttp\Client(['headers'=>['Content-Type'=>'application/json']]);
		$client = new HttpClient(new GuzzleAdapter($guzzleClient), new GuzzleMessageFactory());
		$api = new OneSignal($config, $client);

		try {
			$api->notifications->add([
				'headings' => [
					'en' => $title
				],
				'contents' => [
					'en' => $infoText
				],
				'url' => "https://map.cardinalguild.com/" . $server . "/" . $island->getId(),
				'big_picture' => $url,
				'adm_big_picture' => $url,
				'chrome_big_picture' => $url,
				'included_segments' => ['Subscribed Users'],
				'data' => ['island_id' => $island->getId(), 'island_guid' => $island->getGuid(), 'alliance'=>$newAlliance->getName()]
			]);
		} catch (\Exception $e) { }
	}

    private function getPreviousAllianceName(IslandTerritoryControl $territoryControl) {
		$logRepo = $this->entityManager->getRepository('Gedmo\Loggable\Entity\LogEntry');
		$logEntries = $logRepo->getLogEntries($territoryControl);
		/**
		 * @var $logEntry LogEntry
		 */
		foreach($logEntries as $logEntry) {
			$data = $logEntry->getData();
			if(array_key_exists('alliance', $data)) {
				if($data['alliance'] && isset($data['alliance']['id'])) {
					$alliance = $this->allianceRepo->find($data['alliance']['id']);
					if($alliance) {
						return $alliance->getName();
                    }
                }
			}
		}
		return "Unclaimed";
	}
}
