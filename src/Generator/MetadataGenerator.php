<?php

declare(strict_types=1);

namespace GoetasWebservices\SoapServices\Metadata\Generator;

use Doctrine\Inflector\InflectorFactory;
use GoetasWebservices\XML\SOAPReader\Soap\Operation;
use GoetasWebservices\XML\SOAPReader\Soap\OperationMessage;
use GoetasWebservices\XML\SOAPReader\Soap\Service;
use GoetasWebservices\XML\WSDLReader\Wsdl\Message\Part;
use GoetasWebservices\XML\WSDLReader\Wsdl\PortType\Param;
use GoetasWebservices\Xsd\XsdToPhp\Naming\NamingStrategy;

class MetadataGenerator implements MetadataGeneratorInterface
{
    /**
     * @var string[]
     */
    protected $namespaces = [];
    /**
     * @var string[]
     */
    protected $alternativeEndpoints = [];

    /**
     * @var string[][]
     */
    protected $baseNs = [
        '1.1' => [
            'headers' => '\\SoapEnvelope\\Headers',
            'parts' => '\\SoapParts',
            'messages' => '\\SoapEnvelope\\Messages',
        ],
        '1.2' => [
            'headers' => '\\SoapEnvelope12\\Headers',
            'parts' => '\\SoapParts',
            'messages' => '\\SoapEnvelope12\\Messages',
        ],
    ];
    /**
     * @var NamingStrategy
     */
    private $namingStrategy;
    /**
     * @var bool
     */
    private $unwrap = false;

    /**
     * @param array $namespaces
     */
    public function __construct(NamingStrategy $namingStrategy, array $namespaces)
    {
        $this->namespaces = $namespaces;
        $this->namingStrategy = $namingStrategy;
    }

    public function addAlternativeEndpoint(string $service, string $port, string $endPoint): void
    {
        if (0 === strpos($endPoint, 'env(') && ')' === substr($endPoint, -1) && 'env()' !== $endPoint) {
            $endPoint = '%' . $endPoint . '%';
        }

        $this->alternativeEndpoints[$service][$port] = $endPoint;
    }

    public function setUnwrap(bool $mode = true): void
    {
        $this->unwrap = $mode;
    }

    /**
     * @param Service[] $soapServices
     *
     * @return array
     */
    public function generate(array $soapServices): array
    {
        $services = [];
        foreach ($soapServices as $soapService) {
            $port = $soapService->getPort();

            $endpoint = $this->alternativeEndpoints[$port->getService()->getName()][$port->getName()] ?? $soapService->getAddress();

            $services[$port->getService()->getName()][$port->getName()] = [
                'operations' => $this->generateService($soapService),
                'unwrap' => $this->unwrap,
                'endpoint' => $endpoint,
                'version' => $soapService->getVersion(),
            ];
        }

        return $services;
    }

    protected function generateService(Service $service): array
    {
        $operations = [];

        foreach ($service->getOperations() as $operation) {
            $operations[$operation->getOperation()->getName()] = $this->generateOperation($operation, $service);
        }

        return $operations;
    }

    protected function generateOperation(Operation $soapOperation, Service $service): array
    {
        $inflector = InflectorFactory::create()->build();
        return [
            'action' => $soapOperation->getAction(),
            'style' => $soapOperation->getStyle(),
            'version' => $service->getVersion(),
            'name' => $soapOperation->getOperation()->getName(),
            'method' => $inflector->camelize($soapOperation->getOperation()->getName()),
            'input' => $this->generateInOut($soapOperation, $soapOperation->getInput(), $soapOperation->getOperation()->getPortTypeOperation()->getInput(), 'Input', $service),
            'output' => $this->generateInOut($soapOperation, $soapOperation->getOutput(), $soapOperation->getOperation()->getPortTypeOperation()->getOutput(), 'Output', $service),
            'fault' => [],
        ];
    }

    protected function generateInOut(Operation $operation, OperationMessage $operationMessage, Param $param, string $direction, Service $service): array
    {
        $xmlNs = $operation->getOperation()->getDefinition()->getTargetNamespace();
        if (!isset($this->namespaces[$xmlNs])) {
            throw new \Exception(sprintf("Can not find a PHP namespace to be associated with '%s' XML namespace", $xmlNs));
        }

        $inflector = InflectorFactory::create()->build();
        $ns = $this->namespaces[$xmlNs];
        $operation = [
            'message_fqcn' => $ns
                . $this->baseNs[$service->getVersion()]['messages'] . '\\'
                . $inflector->classify($operationMessage->getMessage()->getOperation()->getName())
                . $direction,
            'headers_fqcn' => $ns
                . $this->baseNs[$service->getVersion()]['headers'] . '\\'
                . $inflector->classify($operationMessage->getMessage()->getOperation()->getName())
                . $direction,
            'part_fqcn' => $ns
                . $this->baseNs[$service->getVersion()]['parts'] . '\\'
                . $inflector->classify($operationMessage->getMessage()->getOperation()->getName())
                . $direction,
            'parts' => $this->getParts($param->getMessage()->getParts()),
        ];

        return $operation;
    }

    /**
     * @param Part[] $messageParts
     *
     * @return array
     */
    private function getParts(array $messageParts): array
    {
        $parts = [];
        foreach ($messageParts as $part) {
            if ($part->getElement()) {
                $partName = $this->namingStrategy->getPropertyName($part->getElement());
            } else {
                $partName = $this->namingStrategy->getPropertyName($part);
            }

            if ($part->getType()) {
                $parts[$partName] = $this->namingStrategy->getTypeName($part->getType());
            } else {
                $parts[$partName] = $this->namingStrategy->getItemName($part->getElement());
            }
        }

        return $parts;
    }
}
