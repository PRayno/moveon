<?php
namespace PRayno\MoveOnApi;


use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;


class MoveOn
{
    protected $service_url;
    protected $certificatePath;
    protected $keyFilePath;
    protected $certificatePassword;
    protected $entities;

    /**
     * Request constructor.
     * @param string $service_url
     * @param string $certificatePath
     * @param string $keyFilePath
     * @param string $certificatePassword
     */
    public function __construct(string $service_url, string $certificatePath, string $keyFilePath, string $certificatePassword)
    {
        $this->service_url = $service_url;
        $this->certificatePath = $certificatePath;
        $this->keyFilePath = $keyFilePath;
        $this->certificatePassword = $certificatePassword;
        $this->entities = Yaml::parse(file_get_contents(__DIR__ . "/Resources/entities.yml"));
    }

    /**
     * Send a plain query to MoveOn
     * @param $entity
     * @param $action
     * @param $data
     * @param string $method
     * @param int $timeout
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    public function sendQuery($entity,$action,$data,$method="queue",$timeout=10)
    {
        if (!isset($this->entities[$entity]))
            throw new \Exception("Entity $entity does not exist");

        $curl_post_data = [
            'method' => $method,
            'entity' => $entity,
            'action' => $action,
            'data' => $data
        ];

        $client = new Client();
        $response = $client->post($this->service_url,[
            'cert' => [$this->certificatePath, $this->certificatePassword],
            'ssl_key' => [$this->keyFilePath, $this->certificatePassword],
            'form_params' => $curl_post_data
        ]);

        $content = $response->getBody()->getContents();
        $crawler = new Crawler($content);
        $moveonResponse = json_decode($crawler->filterXPath("//response")->text());
        if (!isset($moveonResponse->queueId))
            throw new \Exception("The MoveON server did not respond properly");

        // Retrieve data
        $start = time();
        while (time() < $start+$timeout)
        {
            $curl_post_data = array(
                'method' => 'get',
                'id' => (int) $moveonResponse->queueId
            );

            $response = $client->post($this->service_url,[
                'cert' => [$this->certificatePath, $this->certificatePassword],
                'ssl_key' => [$this->keyFilePath, $this->certificatePassword],
                'form_params' => $curl_post_data
            ]);

            $content = $response->getBody()->getContents();

            $crawler = new Crawler($content);
            if ($crawler->filterXPath("//status")->text() != "processing")
            {
                $responseContent = substr(urldecode($crawler->filterXPath("//response")->text()),1,-1);
                $responseContent = str_replace("\/","/",$responseContent);
                return simplexml_load_string($responseContent);
            }
        }
    }

    /**
     * Retrieve data from MoveON DB
     * @param string $entity
     * @param array $criteria
     * @param array $sort
     * @param int $rows
     * @param int $page
     * @param array $columns
     * @param string $locale
     * @param string $search
     * @param string $method
     * @param int $timeout
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    public function findBy(string $entity,array $criteria, array $sort=["id"=>"asc"], int $rows=100, int $page=1,array $columns=[],string $locale="eng",string $search="true",$method="queue",$timeout=10)
    {
        if (!isset($this->entities[$entity]))
            throw new \Exception("Entity $entity does not exist");

        $rules='';
        $i=1;

        foreach ($criteria as $field=>$value)
        {
            if (false === $this->validateField($field,$entity))
                throw new \Exception("The field $field does not belong to the entity $entity");

            $rules .= '{\"field\":\"'.$this->prefix($field,$entity).'\",\"op\":\"eq\",\"data\":\"'.$value.'\"}';


            if (count($criteria) > $i)
                $rules .= ',';

            $i++;
        }

        if (empty($columns))
            $columns = $this->entities[$entity];

        $visibleColumns=[];
        foreach ($columns as $column)
        {
            $visibleColumns[] = $this->prefix($column,$entity);
        }

        $visibleColumns = implode(";",$visibleColumns);


        $filter = '{"filters":"{\"groupOp\":\"AND\",\"rules\":['.$rules.']}","visibleColumns":"'.$visibleColumns.'","locale":"'.$locale.'","sortName":"'.$this->prefix(key($sort),$entity).'","sortOrder":"'.current($sort).'","_search":"'.$search.'","page":"'.$page.'","rows":"'.$rows.'"}';

        try {
            return $this->sendQuery($entity,"list",$filter,$method,$timeout);
        }
        catch (\Exception $exception)
        {
            throw $exception;
        }
    }

    /**
     * Save data to MoveON DB
     * @param string $entity
     * @param array $data
     * @param string $method
     * @param int $timeout
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    public function save(string $entity,array $data,$method="queue",$timeout=10)
    {
        if (!isset($this->entities[$entity]))
            throw new \Exception("Entity $entity does not exist");

        $i=1;
        $dataString = '{"entity":"'.$entity.'",';
        foreach ($data as $field=>$value)
        {
            if (false === $this->validateField($field,$entity))
                throw new \Exception("The field $field does not belong to the entity $entity");

            $dataString .='"'.$field.'":"'.$value.'"';

            if (count($data) > $i)
                $dataString .= ',';

            $i++;
        }

        try {
            return $this->sendQuery($entity,'save','{"entity":"'.$entity.'",'.$dataString.'}',$method,$timeout);

        }
        catch (\Exception $exception)
        {
            throw $exception;
        }
    }

    /**
     * @param $field
     * @param $object
     * @return string
     */
    private function prefix($field,$object)
    {
        if (substr($field,0,6) == 'custom')
            return $field;

        if ("course-unit" == $object)
            $prefix = "courseunit.";
        else
            $prefix = str_replace("-","_",$object).".";

        return $prefix.$field;
    }

    /**
     * @param $field
     * @param $entity
     * @return bool
     */
    private function validateField($field,$entity)
    {
        if (substr($field,0,6) == 'custom')
            return true;

        return in_array($field,$this->entities[$entity]);
    }
}