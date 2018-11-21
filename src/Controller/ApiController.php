<?php


namespace App\Controller;


use App\Entity\Island;
use App\Entity\IslandImage;
use App\Repository\IslandRepository;
use FOS\RestBundle\Controller\Annotations\View;
use FOS\RestBundle\Controller\FOSRestController;
use GeoJson\Feature\Feature;
use GeoJson\Feature\FeatureCollection;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use function MongoDB\BSON\toJSON;
use Nelmio\ApiDocBundle\Annotation\Model;
use Psr\Log\LoggerInterface;
use Swagger\Annotations as SWG;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * Class ApiController
 * @package App\Controller
 * @Route("/api")
 */
class ApiController extends FOSRestController
{
    /**
     * Post a PVE report of materials
     *
     * @Route("/pve/report.{_format}", methods={"POST"}, defaults={ "_format": "json" })
     * @SWG\Response(
     *     response=200,
     *     description="Returns all marker data for islands, if query input given, gives islands by search"
     * )
     * @SWG\Tag(name="reporting")
     * @Cache(public=true, expires="now", mustRevalidate=true)
     */
    public function postPveReportAction(Request $request)
    {

    }

    /**
     * Post a PVE report of materials
     *
     * @Route("/pvp/report.{_format}", methods={"POST"}, defaults={ "_format": "json" })
     * @SWG\Response(
     *     response=200,
     *     description="Returns all marker data for islands, if query input given, gives islands by search"
     * )
     * @SWG\Tag(name="reporting")
     * @Cache(public=true, expires="now", mustRevalidate=true)
     */
    public function postPvpReportAction(Request $request)
    {

    }


    /**
     * Returns all marker data for islands, if query input given, gives islands by search
     *
     * @Route("/islands.{_format}", methods={"GET"}, defaults={ "_format": "json" })
     * @SWG\Response(
     *     response=200,
     *     description="Returns all marker data for islands, if query input given, gives islands by search"
     * )
     * @SWG\Tag(name="islands")
     * @Cache(public=true, expires="now", mustRevalidate=true)
     */
    public function getIslandMarkersAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        /** @var CacheManager */
        $imagineCacheManager = $this->get('liip_imagine.cache.manager');

        /** @var UploaderHelper */
        $uploadHelper = $this->get('vich_uploader.templating.helper.uploader_helper');

        /**
         * @var IslandRepository $islandRepo
         */
        $islandRepo = $em->getRepository('App:Island');

        if($request->query->count()) {
            $islands = $islandRepo->getPublishedIslandsByQuery($request->query->all());
        } else {
            $islands = $islandRepo->getPublishedIslands();
        }

        $intlDateFormatter = new \IntlDateFormatter(
            $request->getPreferredLanguage(),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::MEDIUM
        );

        $markers = [];
        /**
         * @var $island Island
         */
        foreach($islands as $island) {
            $point = new \GeoJson\Geometry\Point([round($island->getLat(),2), round($island->getLng(),2)]);

            $data = [
                'id'=>$island->getId(),
                'name'=>$island->getName(),
                'nickName'=>$island->getNickname(),
                'fullName'=>$island->__toString(),
                'slug'=>$island->getSlug(),
                'key'=>$island->getId().'-'.$island->getSlug(),
                'type'=>$island->getType()?'kioki':'saborian',
                'databanks'=>(integer)$island->getDatabanks(),
                'altitude'=>(integer)$island->getAltitude(),
                'creator'=>$island->getCreator()->getName(),
                'creatorWorkshopUrl'=>$island->getCreator()->getWorkshopUrl(),
                'surveyCreatedBy'=>$island->getSurveyCreatedBy()->__toString(),
                'surveyUpdatedBy'=>$island->getSurveyUpdatedBy()->__toString(),
                'respawners'=>(bool)$island->hasRespawners(),
                'dangerous'=>(bool)$island->isDangerous(),
                'turrets'=>(bool)$island->hasTurrets(),
                'workshopUrl'=>$island->getWorkshopUrl(),
                'createdAt'=>$intlDateFormatter->format($island->getCreatedAt()),
                'updatedAt'=>$intlDateFormatter->format($island->getUpdatedAt())
            ];


            $pveMetals = [];
            foreach($island->getPveMetals() as $pveMetal) {
                if($pveMetal->__toString() !== "New Island Metal") {
                    $pveMetals[] = $pveMetal->__toString();
                }
            }
            $pveTrees = [];
            foreach($island->getPveTrees() as $pveTree) {
                if($pveTree->__toString() !== "New Island Tree") {
                    $pveTrees[] = $pveTree->__toString();
                }
            }
            $data['pveMaterials'] = array_merge($pveMetals, $pveTrees);

            $pvpMetals = [];
            foreach($island->getPvpMetals() as $pvpMetal) {
                if($pvpMetal->__toString() !== "New Island Metal") {
                    $pvpMetals[] = $pvpMetal->__toString();
                }
            }
            $pvpTrees = [];
            foreach($island->getPvpTrees() as $pvpTree) {
                if($pvpTree->__toString() !== "New Island Tree") {
                    $pvpTrees[] = $pvpTree->__toString();
                }
            }

            $data['pvpMaterials'] = array_merge($pvpMetals, $pvpTrees);

            /**
             * @var IslandImage $firstImage
             */
            $firstImage = $island->getImages()->first();
            $secondImage = $island->getImages()->get(1);


            if($firstImage) {
                $imagePath = $uploadHelper->asset($firstImage, 'imageFile');

                $data['imageIcon'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_small');
                $data['imageIconBig'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_big');
                if($secondImage) {
                    $secondImagePath = $uploadHelper->asset($secondImage, 'imageFile');
                    $data['imagePopup'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_popup');
                    $data['imageMedium'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_tile_4by3');
                    $data['imageLarge'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_tile_16by9');
                } else {

                    $data['imagePopup'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_popup');
                    $data['imageMedium'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_4by3');
                    $data['imageLarge'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_16by9');
                }
                $data['imageOriginal'] = $request->getSchemeAndHttpHost().$imagePath;
            }


            $markers[] = new Feature($point, $data, $island->getId());

        }
        $collection = new FeatureCollection($markers);
        return $collection;

    }

    /**
     * Returns all island ids and lag/lng, name, if query input given, gives islands by search
     *
     * @Route("/search.{_format}", methods={"GET"}, defaults={ "_format": "json" })
     * @SWG\Response(
     *     response=200,
     *     description="Returns all island ids, if query input given, gives islands by search"
     * )
     * @SWG\Tag(name="islands")
     * @SWG\Parameter(
     *     name="quality",
     *     in="query",
     *     type="integer",
     *     description="Search for this quality only in metals",
     *     required=false
     * )
     * @SWG\Parameter(
     *     name="minquality",
     *     in="query",
     *     type="integer",
     *     description="Search for this minimum quality only in metals",
     *     required=false
     * )
     * @SWG\Parameter(
     *     name="maxquality",
     *     in="query",
     *     type="integer",
     *     description="Search for this maximum quality only in metals",
     *     required=false
     * )
     * @SWG\Parameter(
     *     name="metal",
     *     in="query",
     *     type="string",
     *     description="Search for a particular metal type by name",
     *     required=false
     * )
     * @SWG\Parameter(
     *     name="tree",
     *     in="query",
     *     type="string",
     *     description="Search for a particular tree type by name",
     *     required=false
     * )
     * @SWG\Parameter(
     *     name="creator",
     *     in="query",
     *     type="string",
     *     description="Search by creator name",
     *     required=false
     * )
     * @SWG\Parameter(
     *     name="island",
     *     in="query",
     *     type="string",
     *     description="Name of island that you are looking for",
     *     required=false
     * )
     * @Cache(public=true, expires="now", mustRevalidate=true)
     */
    public function getIslandSearchAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        /**
         * @var IslandRepository $islandRepo
         */
        $islandRepo = $em->getRepository('App:Island');

        if(!$request->query->count()) {
            throw new BadRequestHttpException('Paremeters are required');
        }

        $results = $islandRepo->getPublishedIslandsByQueryLatLngOnly($request->query->all());

        return array_map(function($item) {
            $item['latLng'] = [
              'lat' => $item['lat'],
              'lng' => $item['lng']
            ];
            unset($item['lat']);
            unset($item['lng']);
            return $item;
        }, $results);
    }

    /**
     * Returns all island for the PVE map
     *
     * @Route("/pve/islands.{_format}", methods={"GET"}, defaults={ "_format": "json" })
     * @SWG\Response(
     *     response=200,
     *     description="Returns all island for the PVE map"
     * )
     * @SWG\Tag(name="islands")
     * @Cache(public=true, expires="now", mustRevalidate=true)
     */
    public function getPveIslandsAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        /** @var CacheManager */
        $imagineCacheManager = $this->get('liip_imagine.cache.manager');

        /** @var UploaderHelper */
        $uploadHelper = $this->get('vich_uploader.templating.helper.uploader_helper');

        /**
         * @var IslandRepository $islandRepo
         */
        $islandRepo = $em->getRepository('App:Island');
        $islands = $islandRepo->getPublishedPveIslands();

        $intlDateFormatter = new \IntlDateFormatter(
            $request->getPreferredLanguage(),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::MEDIUM
        );

        $markers = [];
        /**
         * @var $island Island
         */
        foreach($islands as $island) {
            $point = new \GeoJson\Geometry\Point([round($island->getLat(),2), round($island->getLng(),2)]);

            $data = [
                'id'=>$island->getId(),
                'name'=>$island->getName(),
                'nickName'=>$island->getNickname(),
                'fullName'=>$island->__toString(),
                'slug'=>$island->getSlug(),
                'key'=>$island->getId().'-'.$island->getSlug(),
                'type'=>$island->getType()?'kioki':'saborian',
                'databanks'=>(integer)$island->getDatabanks(),
                'altitude'=>(integer)$island->getAltitude(),
                'creator'=>$island->getCreator()->getName(),
                'creatorWorkshopUrl'=>$island->getCreator()->getWorkshopUrl(),
                'surveyCreatedBy'=>$island->getSurveyCreatedBy()->__toString(),
                'surveyUpdatedBy'=>$island->getSurveyUpdatedBy()->__toString(),
                'respawners'=>(bool)$island->hasRespawners(),
                'dangerous'=>(bool)$island->isDangerous(),
                'turrets'=>(bool)$island->hasTurrets(),
                'workshopUrl'=>$island->getWorkshopUrl(),
                'createdAt'=>$intlDateFormatter->format($island->getCreatedAt()),
                'updatedAt'=>$intlDateFormatter->format($island->getUpdatedAt())
            ];


            $data['metals'] = [];
            foreach($island->getPveMetals() as $pveMetal) {
                $metal = [];
                $metal['type_id'] = $pveMetal->getType()->getId();
                $metal['name'] = $pveMetal->getType()->__toString();
                $metal['quality'] = $pveMetal->getQuality();
                $data['metals'][] = $metal;
            }
            $data['trees'] = [];
            foreach($island->getPveTrees() as $pveTree) {
                $tree = [];
                $tree['type_id'] = $pveTree->getType()->getId();
                $tree['name'] = $pveTree->getType()->__toString();
                $data['trees'][] = $tree;
            }

            /**
             * @var IslandImage $firstImage
             */
            $firstImage = $island->getImages()->first();
            $secondImage = $island->getImages()->get(1);


            if($firstImage) {
                $imagePath = $uploadHelper->asset($firstImage, 'imageFile');

                $data['imageIcon'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_small');
                $data['imageIconBig'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_big');
                if($secondImage) {
                    $secondImagePath = $uploadHelper->asset($secondImage, 'imageFile');
                    $data['imagePopup'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_popup');
                    $data['imageMedium'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_tile_4by3');
                    $data['imageLarge'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_tile_16by9');
                } else {
                    $data['imagePopup'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_popup');
                    $data['imageMedium'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_4by3');
                    $data['imageLarge'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_16by9');
                }
                $data['imageOriginal'] = $request->getSchemeAndHttpHost().$imagePath;
            }
            $markers[] = new Feature($point, $data, $island->getId());
        }
        $collection = new FeatureCollection($markers);
        return $collection;
    }

    /**
     * Returns all island for the PVP map
     *
     * @Route("/pvp/islands.{_format}", methods={"GET"}, defaults={ "_format": "json" })
     * @SWG\Response(
     *     response=200,
     *     description="Returns all island data for the PVP map"
     * )
     * @SWG\Tag(name="islands")
     * @Cache(public=true, expires="now", mustRevalidate=true)
     */
    public function getPvpIslandsAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        /** @var CacheManager */
        $imagineCacheManager = $this->get('liip_imagine.cache.manager');

        /** @var UploaderHelper */
        $uploadHelper = $this->get('vich_uploader.templating.helper.uploader_helper');

        /**
         * @var IslandRepository $islandRepo
         */
        $islandRepo = $em->getRepository('App:Island');
        $islands = $islandRepo->getPublishedPvpIslands();

        $intlDateFormatter = new \IntlDateFormatter(
            $request->getPreferredLanguage(),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::MEDIUM
        );

        $markers = [];
        /**
         * @var $island Island
         */
        foreach($islands as $island) {
            $point = new \GeoJson\Geometry\Point([round($island->getLat(),2), round($island->getLng(),2)]);

            $data = [
                'id'=>$island->getId(),
                'name'=>$island->getName(),
                'nickName'=>$island->getNickname(),
                'fullName'=>$island->__toString(),
                'slug'=>$island->getSlug(),
                'key'=>$island->getId().'-'.$island->getSlug(),
                'type'=>$island->getType()?'kioki':'saborian',
                'databanks'=>(integer)$island->getDatabanks(),
                'altitude'=>(integer)$island->getAltitude(),
                'creator'=>$island->getCreator()->getName(),
                'creatorWorkshopUrl'=>$island->getCreator()->getWorkshopUrl(),
                'surveyCreatedBy'=>$island->getSurveyCreatedBy()->__toString(),
                'surveyUpdatedBy'=>$island->getSurveyUpdatedBy()->__toString(),
                'respawners'=>(bool)$island->hasRespawners(),
                'dangerous'=>(bool)$island->isDangerous(),
                'turrets'=>(bool)$island->hasTurrets(),
                'workshopUrl'=>$island->getWorkshopUrl(),
                'createdAt'=>$intlDateFormatter->format($island->getCreatedAt()),
                'updatedAt'=>$intlDateFormatter->format($island->getUpdatedAt())
            ];


            $data['metals'] = [];
            foreach($island->getPvpMetals() as $pvpMetal) {
                $metal = [];
                $metal['type_id'] = $pvpMetal->getType()->getId();
                $metal['name'] = $pvpMetal->getType()->__toString();
                $metal['quality'] = $pvpMetal->getQuality();
                $data['metals'][] = $metal;
            }
            $data['trees'] = [];
            foreach($island->getPvpTrees() as $pvpTree) {
                $tree = [];
                $tree['type_id'] = $pvpTree->getType()->getId();
                $tree['name'] = $pvpTree->getType()->__toString();
                $data['trees'][] = $tree;
            }

            /**
             * @var IslandImage $firstImage
             */
            $firstImage = $island->getImages()->first();
            $secondImage = $island->getImages()->get(1);


            if($firstImage) {
                $imagePath = $uploadHelper->asset($firstImage, 'imageFile');

                $data['imageIcon'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_small');
                $data['imageIconBig'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_big');
                if($secondImage) {
                    $secondImagePath = $uploadHelper->asset($secondImage, 'imageFile');
                    $data['imagePopup'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_popup');
                    $data['imageMedium'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_tile_4by3');
                    $data['imageLarge'] = $imagineCacheManager->getBrowserPath($secondImagePath, 'island_tile_16by9');
                } else {
                    $data['imagePopup'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_popup');
                    $data['imageMedium'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_4by3');
                    $data['imageLarge'] = $imagineCacheManager->getBrowserPath($imagePath, 'island_tile_16by9');
                }
                $data['imageOriginal'] = $request->getSchemeAndHttpHost().$imagePath;
            }
            $markers[] = new Feature($point, $data, $island->getId());
        }
        $collection = new FeatureCollection($markers);
        return $collection;
    }

}
