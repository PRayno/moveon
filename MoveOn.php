<?php
namespace PRayno\MoveOnApi;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;


class MoveOn
{
    protected array $entities_read;
    protected array $entities_write;
    protected int $maxRowsPerQuery;
    private HttpClientInterface $client;

    /**
     * Request constructor.
     * @param HttpClientInterface $client
     * @param int $maxRowsPerQuery
     */
    public function __construct(HttpClientInterface $client, int $maxRowsPerQuery=250)
    {
        $this->client = $client;
        $this->maxRowsPerQuery = $maxRowsPerQuery;
        $this->entities_read = Yaml::parse(file_get_contents(__DIR__ . "/Resources/entities_read.yml"));
        $this->entities_write = Yaml::parse(file_get_contents(__DIR__ . "/Resources/entities_write.yml"));
    }

    /**
     * Send a plain query to MoveOn
     * @param $entity
     * @param $action
     * @param $data
     * @param string $method
     * @param int $timeout
     * @param bool $retrieveData
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    public function sendQuery(string $entity,string $action,string $data,string $method="queue",int $timeout=10,bool $retrieveData=true): \SimpleXMLElement
    {
        $entities = ($action=="save" ? $this->entities_write:$this->entities_read);

        if (!isset($entities[$entity]))
            throw new \Exception("Entity $entity does not exist");

        $curl_post_data = [
            'method' => $method,
            'entity' => $entity,
            'action' => $action,
            'data' => $data
        ];

        $response = $this->client->request('POST',"",['body' => $curl_post_data]);
        $content = $response->getContent();

        $crawler = new Crawler($content);
        $moveonResponse = json_decode($crawler->filterXPath("//response")->text());

        if (!isset($moveonResponse->queueId))
        {
            throw new \Exception("The MoveON server did not respond properly");
        }

        if ($retrieveData==false)
            return $moveonResponse->queueId;

        // Retrieve data
        $start = time();
        while (time() < $start+$timeout)
        {
            $curl_post_data = array(
                'method' => 'get',
                'id' => (int) $moveonResponse->queueId
            );

            $response = $this->client->request('POST',"",['body' => $curl_post_data]);
            $content = $response->getContent();

            $crawler = new Crawler($content);
            if ($crawler->filterXPath("//status")->text() == "success")
            {
                $responseContent = $crawler->filterXPath("//response")->html();
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
    public function findBy(string $entity,array $criteria, array $sort=["id"=>"asc"], int $rows=250, int $page=1,array $columns=[],string $locale="eng",string $search="true",string $method="queue",int $timeout=10)
    {
        if (!isset($this->entities_read[$entity]))
            throw new \Exception("Entity $entity does not exist");

        $rules='';
        $i=1;

        $params=[];
        foreach ($criteria as $field=>$value)
        {
            if (false === $this->validateField($field,$entity,"read"))
                throw new \Exception("The field $field does not belong to the entity $entity");

            $params[] = [
                "field"=>$this->prefix($field,$entity),
                "op"=>(is_array($value)?"in":"eq"),
                "data"=>(is_array($value)?implode(",",$value):$value)
            ];

            if (count($criteria) > $i)
                $rules .= ',';

            $i++;
        }

        if (empty($columns))
            $columns = $this->entities_read[$entity];

        $visibleColumns=[];
        foreach ($columns as $column)
        {
            $visibleColumns[] = $this->prefix($column,$entity);
        }

        $visibleColumns = implode(";",$visibleColumns);

        $filterElements = [
            "filters"=>json_encode(["groupOp"=>"AND","rules"=>$params]),
            "visibleColumns"=>json_encode($visibleColumns),
            "locale"=>$locale,
            "sortName"=>$this->prefix(key($sort),$entity),
            "sortOrder"=>current($sort),
            "_search"=>$search,
            "page"=>$page,
            "rows"=>$rows
        ];

        // Simple query if the page number is set directly
        if ($page>1)
        {
            if ($rows > $this->maxRowsPerQuery)
                throw new \Exception("The number of rows for a given page must not exceed the limit of ".$this->maxRowsPerQuery);

            try {
                return $this->sendQuery($entity,"list",json_encode($filterElements),$method,$timeout);
            }
            catch (\Exception $exception)
            {
                throw $exception;
            }
        }

        // Recreate xml response after pagination
        $remainingRows = $rows;
        for ($page=1;$page<=ceil($rows/$this->maxRowsPerQuery);$page++)
        {
            $rowsToRetrieve = ($remainingRows < $this->maxRowsPerQuery ? $remainingRows:$this->maxRowsPerQuery);
            $remainingRows = $remainingRows-$rowsToRetrieve;

            $filterElements["rows"] = $rowsToRetrieve;

            $filter = json_encode($filterElements);

            try {
                $response = $this->sendQuery($entity,"list",$filter,$method,$timeout);
            }
            catch (\Exception $exception)
            {
                throw $exception;
            }

            if ($page==1)
            {
                $finalResponse = clone $response;
                unset($finalResponse->rows);
            }

            foreach ($response->rows as $row)
            {
                $this->simplexml_import_simplexml($finalResponse,$row);
            }

            if ($finalResponse->records == count($finalResponse->rows))
                break;
        }

        return $finalResponse;
    }

    /**
     * Save data to MoveON DB
     * @param string $entity
     * @param array $data
     * @param string $method
     * @param int $timeout
     * @param bool $retrieveData
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    public function save(string $entity, array $data, string $method="queue", int $timeout=10,bool $retrieveData=true)
    {
        if (!isset($this->entities_write[$entity]))
            throw new \Exception("Entity $entity does not exist");

        $elements = ["entity"=>$entity];
        foreach ($data as $field=>$value)
        {
            if (false === $this->validateField($field,$entity,"write"))
                throw new \Exception("The field $field does not belong to the entity $entity");

            $elements[$this->prefix($field,$entity)] = $value;
        }

        try {
            return $this->sendQuery($entity,'save',json_encode($elements),$method,$timeout,$retrieveData);
        }
        catch (\Exception $exception)
        {
            throw $exception;
        }
    }

    /**
     * Find courses list for a student
     * @param string $studentnumber
     * @param string $courseCodeField
     * @return array
     * @throws \Exception
     */
    public function getUserCourses(string $studentnumber,string $courseCodeField="courseunit.code")
    {
        try {
            $data = $this->findBy("person",["matriculation_id"=>$studentnumber]);
            if ($data->records == 0)
                throw new \Exception("Could not find student $studentnumber in MoveON database");

            if ($data->records > 1)
                throw new \Exception("Several students were found in MoveON db with the number $studentnumber.");

            $field = "person.id";
            $studentId = $data->rows[0]->$field->__toString();

            // Find stay
            $data = $this->findBy("stay",["person_id"=>$studentId]);
            if ($data->records == 0)
                throw new \Exception("Could not find stay in MoveON database");

            if ($data->records > 1)
                throw new \Exception("Several stays were found in MoveON db for the student $studentnumber.");

            // Find courses
            $stayIdField = "stay.id";
            $courses = $this->findBy("course-unit",["stay.id"=>$data->rows[0]->$stayIdField->__toString()]);
            if ($courses->records == 0)
                throw new \Exception("No course were linked to the student $studentnumber in MoveON database");

            $coursesList=[];
            foreach ($courses->rows as $course)
            {
                $coursesList[] = trim($course->$courseCodeField->__toString());
            }

            return $coursesList;
        }
        catch (\Exception $exception)
        {
            throw $exception;
        }
    }

    /**
     * @param string $entity
     * @param string $readWrite
     * @return mixed
     * @throws \Exception
     */
    public function getEntity(string $entity,string $readWrite="read")
    {
        $entities = ($readWrite=="read" ? $this->entities_read : $this->entities_write);

        if (!isset($entities[$entity]))
            throw new \Exception("Entity $entity does not exist in $readWrite mode");

        return $entities[$entity];
    }

    /**
     * @param $field
     * @param $object
     * @return string
     */
    private function prefix($field,$object)
    {
        if (str_starts_with($field, 'custom'))
            return $field;

        $prefix = str_replace("-","_",$object).".";

        return $prefix.$field;
    }

    /**
     * @param $field
     * @param $entity
     * @param $readWrite
     * @return bool
     * @throws \Exception
     */
    private function validateField($field,$entity,$readWrite)
    {
        if (str_starts_with($field, 'custom'))
            return true;

        return in_array($field,$this->getEntity($entity,$readWrite));
    }

    /**
     * @param \SimpleXMLElement $parent
     * @param \SimpleXMLElement $child
     * @param false $before
     * @return bool
     */
    private function simplexml_import_simplexml(\SimpleXMLElement $parent, \SimpleXMLElement $child, $before = false)
    {
        // check if there is something to add
        if ($child[0] == NULL) {
            return true;
        }

        // if it is a list of SimpleXMLElements default to the first one
        $child = $child[0];

        // insert attribute
        if ($child->xpath('.') != array($child)) {
            $parent[$child->getName()] = (string)$child;
            return true;
        }

        $xml = $child->asXML();

        // remove the XML declaration on document elements
        if ($child->xpath('/*') == array($child)) {
            $pos = strpos($xml, "\n");
            $xml = substr($xml, $pos + 1);
        }

        return $this->simplexml_import_xml($parent, $xml, $before);
    }

    /**
     * @param \SimpleXMLElement $parent
     * @param $xml
     * @param false $before
     * @return bool
     */
    private function simplexml_import_xml(\SimpleXMLElement $parent, $xml, $before = false)
    {
        $xml = (string)$xml;

        // check if there is something to add
        if ($nodata = !strlen($xml) or $parent[0] == NULL) {
            return $nodata;
        }

        // add the XML
        $node     = dom_import_simplexml($parent);
        $fragment = $node->ownerDocument->createDocumentFragment();
        $fragment->appendXML($xml);

        if ($before) {
            return (bool)$node->parentNode->insertBefore($fragment, $node);
        }

        return (bool)$node->appendChild($fragment);
    }
}