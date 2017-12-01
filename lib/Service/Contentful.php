<?php

namespace Netgen\BlockManager\Contentful\Service;

use Contentful\Delivery\Client;
use Contentful\Delivery\DynamicEntry;
use Contentful\Delivery\EntryInterface;
use Contentful\Delivery\Query;
use Contentful\Delivery\Synchronization\DeletedEntry;
use Contentful\ResourceArray;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Netgen\BlockManager\Contentful\Entity\ContentfulEntry;
use RuntimeException;
use Symfony\Cmf\Bundle\RoutingBundle\Doctrine\Orm\Route;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\Filesystem\Filesystem;

final class Contentful
{
    /**
     * @var \Contentful\Delivery\Client
     */
    private $defaultClient;

    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private $fileSystem;

    /**
     * @var array
     */
    private $clientsConfig;

    /**
     * @var string
     */
    private $cacheDir;

    public function __construct(
        Client $defaultClient,
        EntityManagerInterface $entityManager,
        Filesystem $fileSystem,
        array $clientsConfig,
        $cacheDir
    ) {
        $this->defaultClient = $defaultClient;
        $this->entityManager = $entityManager;
        $this->fileSystem = $fileSystem;
        $this->clientsConfig = $clientsConfig;
        $this->cacheDir = $cacheDir;

        if (empty($this->clientsConfig)) {
            throw new RuntimeException('No Contentful clients configured');
        }
    }

    /**
     * Returns the Contentful client with provided name.
     *
     * @param string $name
     *
     * @throws \RuntimeException If client with provided name does not exist
     *
     * @return \Contentful\Delivery\Client
     */
    public function getClientByName($name)
    {
        if (!isset($this->clientsConfig[$name])) {
            throw new RuntimeException(sprintf('Contentful client with "%s" name does not exist.', $name));
        }

        return $this->clientsConfig[$name]['service'];
    }

    /**
     * Returns the Contentful space with provided client name.
     *
     * @param string $name
     *
     * @return \Contentful\Delivery\Space
     */
    public function getSpaceByClientName($name)
    {
        return $this->clientsConfig[$name]['space'];
    }

    /**
     * Returns the Contentful client which serves the space with provided ID.
     *
     * If no client is found, null is returned.
     *
     * @param string $spaceId
     *
     * @return \Contentful\Delivery\Client|null
     */
    public function getClientBySpaceId($spaceId)
    {
        foreach ($this->clientsConfig as $clientConfig) {
            if ($clientConfig['space'] === $spaceId) {
                return $clientConfig['service'];
            }
        }

        return null;
    }

    /**
     * Returns all configured clients.
     *
     * @return \Contentful\Delivery\Client[]
     */
    public function getClients()
    {
        $clients = array();

        foreach ($this->clientsConfig as $clientConfig) {
            $clients[] = $clientConfig['service'];
        }

        return $clients;
    }

    /**
     * Returns the content type with specified ID.
     *
     * If no content type is found, null is returned.
     *
     * @param string $id
     *
     * @return \Contentful\Delivery\ContentType|null
     */
    public function getContentType($id)
    {
        foreach ($this->clientsConfig as $clientConfig) {
            /** @var \Contentful\Delivery\Client $client */
            $client = $clientConfig['service'];

            foreach ($client->getContentTypes()->getItems() as $contentType) {
                /** @var \Contentful\Delivery\ContentType $contentType */
                if ($contentType->getId() === $id) {
                    return $contentType;
                }
            }
        }

        return null;
    }

    /**
     * Returns names of all configured clients.
     *
     * @return string[]
     */
    public function getClientsNames()
    {
        return array_keys($this->clientsConfig);
    }

    /**
     * Returns the Contentful entry with provided ID.
     *
     * @param string $id
     *
     * @throws \Exception If entry could not be loaded
     *
     * @return \Netgen\BlockManager\Contentful\Entity\ContentfulEntry
     */
    public function loadContentfulEntry($id)
    {
        $idList = explode('|', $id);
        if (count($idList) !== 2) {
            throw new Exception(
                sprintf(
                    'Item ID %s not valid.',
                    $id
                )
            );
        }

        $client = $this->getClientBySpaceId($idList[0]);

        $contentfulEntry = $this->findContentfulEntry($id);

        if ($contentfulEntry instanceof ContentfulEntry) {
            $contentfulEntry->reviveRemoteEntry($client);
        } else {
            $remoteEntry = $client->getEntry($idList[1]);

            if (!$remoteEntry instanceof EntryInterface) {
                throw new Exception(
                    sprintf(
                        'Entry with ID %s not found.',
                        $id
                    )
                );
            }

            $contentfulEntry = $this->buildContentfulEntry($remoteEntry, $id);
        }

        if ($contentfulEntry->getIsDeleted()) {
            throw new Exception(
                sprintf(
                    'Entry with ID %s deleted.',
                    $id
                )
            );
        }

        return $contentfulEntry;
    }

    /**
     * Returns the list of Contentful entries.
     *
     * @param int $offset
     * @param int $limit
     * @param \Contentful\Delivery\Client $client
     * @param \Contentful\Delivery\Query $query
     *
     * @return \Netgen\BlockManager\Contentful\Entity\ContentfulEntry[]
     */
    public function getContentfulEntries($offset = 0, $limit = 25, Client $client = null, Query $query = null)
    {
        $client = $client ?: $this->defaultClient;

        if ($query === null) {
            $query = new Query();
            $query->setLimit($limit);
            $query->setSkip($offset);
        }

        return $this->buildContentfulEntries($client->getEntries($query), $client);
    }

    /**
     * Returns the count of Contentful entries.
     *
     * @param \Contentful\Delivery\Client $client
     * @param \Contentful\Delivery\Query $query
     *
     * @return int
     */
    public function getContentfulEntriesCount(Client $client = null, Query $query = null)
    {
        $client = $client ?: $this->defaultClient;

        return count($client->getEntries($query));
    }

    /**
     * Searches for Contentful entries.
     *
     * @param string $searchText
     * @param int $offset
     * @param int $limit
     * @param \Contentful\Delivery\Client $client
     *
     * @return \Netgen\BlockManager\Contentful\Entity\ContentfulEntry[]
     */
    public function searchContentfulEntries($searchText, $offset = 0, $limit = 25, Client $client = null)
    {
        $client = $client ?: $this->defaultClient;

        $query = new Query();
        $query->setLimit($limit);
        $query->setSkip($offset);
        $query->where('query', $searchText);

        return $this->buildContentfulEntries($client->getEntries($query), $client);
    }

    /**
     * Returns the count of searched Contentful entries.
     *
     * @param string $searchText
     * @param \Contentful\Delivery\Client $client
     *
     * @return int
     */
    public function searchContentfulEntriesCount($searchText, Client $client = null)
    {
        $client = $client ?: $this->defaultClient;

        $query = new Query();
        $query->where('query', $searchText);

        return count($client->getEntries($query));
    }

    /**
     * Returns the list of clients and content types for usage in Symfony Forms.
     *
     * @return string[]
     */
    public function getClientsAndContentTypesAsChoices()
    {
        $clientsAndContentTypes = array();

        foreach ($this->clientsConfig as $clientName => $clientConfig) {
            /** @var \Contentful\Delivery\Client $client */
            $client = $clientConfig['service'];

            $clientsAndContentTypes[$client->getSpace()->getName()] = $clientName;
            foreach ($client->getContentTypes()->getItems() as $contentType) {
                /* @var \Contentful\Delivery\ContentType $contentType */
                $clientsAndContentTypes['>  ' . $contentType->getName()] = $clientName . '|' . $contentType->getId();
            }
        }

        return $clientsAndContentTypes;
    }

    /**
     * Returns the list of spaces for usage in Symfony Forms.
     *
     * @return string[]
     */
    public function getSpacesAsChoices()
    {
        $spaces = array();

        foreach ($this->clientsConfig as $clientConfig) {
            /** @var \Contentful\Delivery\Client $client */
            $client = $clientConfig['service'];

            $spaces[$client->getSpace()->getName()] = $clientConfig['space'];
        }

        return $spaces;
    }

    /**
     * Returns the list of spaces and content types for usage in Symfony Forms.
     *
     * @return string[]
     */
    public function getSpacesAndContentTypesAsChoices()
    {
        $spaces = array();

        foreach ($this->clientsConfig as $clientConfig) {
            /** @var \Contentful\Delivery\Client $client */
            $client = $clientConfig['service'];

            $contentTypes = array();
            foreach ($client->getContentTypes()->getItems() as $contentType) {
                /* @var \Contentful\Delivery\ContentType $contentType */
                $contentTypes[$contentType->getName()] = $contentType->getId();
            }
            $spaces[$client->getSpace()->getName()] = $contentTypes;
        }

        return $spaces;
    }

    /**
     * Refreshes the Contentful entry for provided remote entry.
     *
     * @param \Contentful\Delivery\DynamicEntry $remoteEntry
     *
     * @return \Netgen\BlockManager\Contentful\Entity\ContentfulEntry|null
     */
    public function refreshContentfulEntry(DynamicEntry $remoteEntry)
    {
        $id = $remoteEntry->getSpace()->getId() . '|' . $remoteEntry->getId();
        $contentfulEntry = $this->findContentfulEntry($id);

        if ($contentfulEntry instanceof ContentfulEntry) {
            $contentfulEntry->setJson(json_encode($remoteEntry));
            $contentfulEntry->setIsPublished(true);
            $this->entityManager->persist($contentfulEntry);
            $this->entityManager->flush();
        } else {
            $contentfulEntry = $this->buildContentfulEntry($remoteEntry, $id);
        }

        return $contentfulEntry;
    }

    /**
     * Unpublishes the Contentful entry for provided remote entry.
     *
     * @param \Contentful\Delivery\Synchronization\DeletedEntry $remoteEntry
     */
    public function unpublishContentfulEntry(DeletedEntry $remoteEntry)
    {
        $id = $remoteEntry->getSpace()->getId() . '|' . $remoteEntry->getId();
        $contentfulEntry = $this->findContentfulEntry($id);
        if ($contentfulEntry instanceof ContentfulEntry) {
            $contentfulEntry->setIsPublished(false);
            $this->entityManager->persist($contentfulEntry);
            $this->entityManager->flush();
        }
    }

    /**
     * Deletes the Contentful entry for provided remote entry.
     *
     * @param \Contentful\Delivery\Synchronization\DeletedEntry $remoteEntry
     */
    public function deleteContentfulEntry(DeletedEntry $remoteEntry)
    {
        $id = $remoteEntry->getSpace()->getId() . '|' . $remoteEntry->getId();
        $contentfulEntry = $this->findContentfulEntry($id);
        if ($contentfulEntry instanceof ContentfulEntry) {
            $contentfulEntry->setIsDeleted(true);
            $this->entityManager->persist($contentfulEntry);

            foreach ($contentfulEntry->getRoutes() as $route) {
                $this->entityManager->remove($route);
            }

            $this->entityManager->flush();
        }
    }

    /**
     * Refreshes space caches for provided client.
     *
     * @param \Contentful\Delivery\Client $client
     */
    public function refreshSpaceCache(Client $client)
    {
        $spacePath = $this->getSpaceCachePath($client);
        $this->fileSystem->dumpFile($spacePath . '/space.json', json_encode($client->getSpace()));
    }

    /**
     * Refreshes content type caches for provided client.
     *
     * @param \Contentful\Delivery\Client $client
     */
    public function refreshContentTypeCache(Client $client)
    {
        $spacePath = $this->getSpaceCachePath($client);
        $contentTypes = $client->getContentTypes();
        foreach ($contentTypes as $contentType) {
            /* @var \Contentful\Delivery\ContentType $contentType */
            $this->fileSystem->dumpFile($spacePath . '/ct-' . $contentType->getId() . '.json', json_encode($contentType));
        }
    }

    /**
     * Returns the cache path for provided client.
     *
     * @param \Contentful\Delivery\Client $client
     *
     * @return string
     */
    public function getSpaceCachePath(Client $client)
    {
        $space = $client->getSpace();
        $spacePath = $this->cacheDir . $space->getId();
        if (!$this->fileSystem->exists($spacePath)) {
            $this->fileSystem->mkdir($spacePath);
        }

        return $spacePath;
    }

    /**
     * Returns the Contentful entry with provided ID from the repository.
     *
     * Returns null if entry could not be found.
     *
     * @param string $id
     *
     * @return \Netgen\BlockManager\Contentful\Entity\ContentfulEntry|null
     */
    private function findContentfulEntry($id)
    {
        return $this->entityManager->getRepository(ContentfulEntry::class)->find($id);
    }

    /**
     * Builds the Contentful entry from provided remote entry.
     *
     * @param \Contentful\Delivery\EntryInterface $remoteEntry
     * @param string $id
     *
     * @return \Netgen\BlockManager\Contentful\Entity\ContentfulEntry|null
     */
    private function buildContentfulEntry(EntryInterface $remoteEntry, $id)
    {
        $contentfulEntry = new ContentfulEntry($remoteEntry);
        $contentfulEntry->setIsPublished(true);
        $contentfulEntry->setJson(json_encode($remoteEntry));

        $route = new Route();
        $route->setName($id);
        $slug = '/' . $this->createSlugPart($contentfulEntry->getSpace()->getName());
        $slug .= '/' . $this->createSlugPart($contentfulEntry->getName());
        $route->setStaticPrefix($slug);
        $route->setDefault(RouteObjectInterface::CONTENT_ID, ContentfulEntry::class . ':' . $id);
        $route->setContent($contentfulEntry);
        $contentfulEntry->addRoute($route); // Create the back-link from content to route

        $this->entityManager->persist($contentfulEntry);
        $this->entityManager->persist($route);
        $this->entityManager->flush();

        return $contentfulEntry;
    }

    /**
     * Builds the slug from provided slug.
     *
     * @param string $string
     *
     * @return string
     */
    private function createSlugPart($string)
    {
        return strtolower(trim(preg_replace('~[^0-9a-z]+~i', '-', html_entity_decode(preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', htmlentities($string, ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8')), '-'));
    }

    /**
     * Builds the Contentful entries from provided remote entries.
     *
     * @param \Contentful\ResourceArray $entries
     * @param \Contentful\Delivery\Client $client
     *
     * @return \Netgen\BlockManager\Contentful\Entity\ContentfulEntry[]
     */
    private function buildContentfulEntries(ResourceArray $entries, Client $client)
    {
        $contentfulEntries = array();

        foreach ($entries as $remoteEntry) {
            $id = $remoteEntry->getSpace()->getId() . '|' . $remoteEntry->getId();
            $contentfulEntry = $this->findContentfulEntry($id);
            if (!$contentfulEntry instanceof ContentfulEntry) {
                $contentfulEntry = $this->buildContentfulEntry($remoteEntry, $id);
            } else {
                $contentfulEntry->reviveRemoteEntry($client);
            }
            $contentfulEntries[] = $contentfulEntry;
        }

        return $contentfulEntries;
    }
}
