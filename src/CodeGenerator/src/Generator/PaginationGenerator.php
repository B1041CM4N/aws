<?php

declare(strict_types=1);

namespace AsyncAws\CodeGenerator\Generator;

use AsyncAws\CodeGenerator\Definition\ListShape;
use AsyncAws\CodeGenerator\Definition\Operation;
use AsyncAws\CodeGenerator\Definition\Pagination;
use AsyncAws\CodeGenerator\Definition\StructureShape;
use AsyncAws\CodeGenerator\Generator\CodeGenerator\TypeGenerator;
use AsyncAws\CodeGenerator\Generator\Naming\NamespaceRegistry;
use AsyncAws\CodeGenerator\Generator\PhpGenerator\ClassBuilder;
use AsyncAws\CodeGenerator\Generator\PhpGenerator\ClassRegistry;
use AsyncAws\Core\Exception\InvalidArgument;
use AsyncAws\Core\Exception\LogicException;
use Nette\PhpGenerator\Parameter;

/**
 * Generate API pagination methods.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 *
 * @internal
 */
class PaginationGenerator
{
    /**
     * @var ClassRegistry
     */
    private $classRegistry;

    /**
     * @var NamespaceRegistry
     */
    private $namespaceRegistry;

    /**
     * @var InputGenerator
     */
    private $inputGenerator;

    /**
     * @var ResultGenerator
     */
    private $resultGenerator;

    /**
     * @var TypeGenerator|null
     */
    private $typeGenerator;

    public function __construct(ClassRegistry $classRegistry, NamespaceRegistry $namespaceRegistry, InputGenerator $inputGenerator, ResultGenerator $resultGenerator, ?TypeGenerator $typeGenerator = null)
    {
        $this->classRegistry = $classRegistry;
        $this->namespaceRegistry = $namespaceRegistry;
        $this->inputGenerator = $inputGenerator;
        $this->resultGenerator = $resultGenerator;
        $this->typeGenerator = $typeGenerator ?? new TypeGenerator($this->namespaceRegistry);
    }

    /**
     * Generate classes for the output. Ie, the result of the API call.
     */
    public function generate(Operation $operation): void
    {
        if (null === $pagination = $operation->getPagination()) {
            throw new LogicException(sprintf('The operation "%s" does not have any pagination to generate', $operation->getName()));
        }
        if (null === $output = $operation->getOutput()) {
            throw new LogicException(sprintf('The operation "%s" does not have output for the pagination', $operation->getName()));
        }

        $this->generateOutputPagination($operation, $pagination, $output);
    }

    private function generateOutputPagination(Operation $operation, Pagination $pagination, StructureShape $shape): void
    {
        $outputClass = $this->resultGenerator->generate($operation);

        $classBuilder = $this->classRegistry->register($outputClass->getFqdn(), true);

        $classBuilder->addImplement(\IteratorAggregate::class);
        $resultKeys = $pagination->getResultkey();
        if (empty($resultKeys)) {
            foreach ($operation->getOutput()->getMembers() as $member) {
                if ($member->getShape() instanceof ListShape) {
                    $resultKeys[] = $member->getName();
                }
            }
        }

        if (empty($resultKeys)) {
            throw new \RuntimeException('Pagination without resultkey is not yet implemented');
        }
        $iteratorBody = '';
        $iteratorTypes = [];
        foreach ($resultKeys as $resultKey) {
            $singlePage = empty($pagination->getOutputToken());
            $iteratorBody .= strtr('yield from $page->PROPERTY_ACCESSOR(SINGLE_PAGE_FLAG);
            ', [
                'PROPERTY_ACCESSOR' => $getter = 'get' . ucfirst(GeneratorHelper::normalizeName($resultKey)),
                'SINGLE_PAGE_FLAG' => $singlePage ? '' : 'true',
            ]);
            $resultShape = $shape->getMember($resultKey)->getShape();

            if (!$resultShape instanceof ListShape) {
                throw new \RuntimeException('Cannot generate a pagination for a non-iterable result');
            }

            $listShape = $resultShape->getMember()->getShape();
            [$returnType, $iteratorType, $memberClassNames] = $this->typeGenerator->getPhpType($listShape);
            foreach ($memberClassNames as $memberClassName) {
                $classBuilder->addUse($memberClassName->getFqdn());
            }

            $iteratorTypes[] = $iteratorType;

            if (!$classBuilder->hasMethod($getter)) {
                throw new \RuntimeException(sprintf('Unable to find the method "%s" in "%s"', $getter, $shape->getName()));
            }

            $method = $classBuilder->getMethod($getter);
            $method
                ->setReturnType('iterable')
                ->setComment('')
            ;
            if ($singlePage) {
                $method
                    ->setBody(strtr('
                        $this->initialize();
                        return $this->PROPERTY_NAME;
                    ', [
                        'PROPERTY_NAME' => GeneratorHelper::normalizeName($resultKey),
                    ]));
            } else {
                $method
                    ->setParameters([(new Parameter('currentPageOnly'))->setType('bool')->setDefaultValue(false)])
                    ->addComment('@param bool $currentPageOnly When true, iterates over items of the current page. Otherwise also fetch items in the next pages.')
                    ->setBody(strtr('
                        if ($currentPageOnly) {
                            $this->initialize();
                            yield from $this->PROPERTY_NAME;

                            return;
                        }

                        PAGE_LOADER_CODE
                    ', [
                        'PROPERTY_NAME' => GeneratorHelper::normalizeName($resultKey),
                        'PAGE_LOADER_CODE' => $this->generateOutputPaginationLoader(
                            strtr('yield from $page->PROPERTY_ACCESSOR(true);', ['PROPERTY_ACCESSOR' => 'get' . ucfirst(GeneratorHelper::normalizeName($resultKey))]),
                            $pagination, $classBuilder, $operation
                        ),
                    ]));
            }

            $method
                ->addComment("@return iterable<$iteratorType>");
        }

        $iteratorType = implode('|', $iteratorTypes);

        $classBuilder->addMethod('getIterator')
            ->setReturnType(\Traversable::class)
            ->addComment('Iterates over ' . implode(' then ', $resultKeys))
            ->addComment("@return \Traversable<$iteratorType>")
            ->setBody($this->generateOutputPaginationLoader($iteratorBody, $pagination, $classBuilder, $operation))
        ;
        $classBuilder->addComment("@implements \IteratorAggregate<$iteratorType>");
    }

    private function generateOutputPaginationLoader(string $iterator, Pagination $pagination, ClassBuilder $classBuilder, Operation $operation): string
    {
        if (empty($pagination->getOutputToken())) {
            return strtr($iterator, ['$page->' => '$this->']);
        }

        $inputToken = $pagination->getInputToken();
        $outputToken = $pagination->getOutputToken();
        if (null === $moreResult = $pagination->getMoreResults()) {
            $moreCondition = '';
            foreach ($outputToken as $index => $property) {
                $moreCondition .= strtr('$page->MORE_ACCESSOR()', [
                    'MORE_ACCESSOR' => 'get' . ucfirst(GeneratorHelper::normalizeName(trim(explode('||', $property)[0]))),
                ]);
            }
        } else {
            $moreCondition = strtr('$page->MORE_ACCESSOR()', [
                'MORE_ACCESSOR' => 'get' . ucfirst(GeneratorHelper::normalizeName($moreResult)),
            ]);
        }
        $setter = '';
        foreach ($inputToken as $index => $property) {
            $setter .= strtr('
                $input->SETTER($page->GETTER());
            ', [
                'SETTER' => 'set' . ucfirst(GeneratorHelper::normalizeName($property)),
                'GETTER' => 'get' . ucfirst(GeneratorHelper::normalizeName(trim(explode('||', $outputToken[$index])[0]))),
            ]);
        }

        $inputClass = $this->inputGenerator->generate($operation);
        $classBuilder->addUse($inputClass->getFqdn());
        $clientClass = $this->namespaceRegistry->getClient($operation->getService());
        $classBuilder->addUse($clientClass->getFqdn());
        $classBuilder->addUse(InvalidArgument::class);

        return strtr('
            $client = $this->awsClient;
            if (!$client instanceOf CLIENT_CLASSNAME) {
                throw new InvalidArgument(\'missing client injected in paginated result\');
            }
            if (!$this->input instanceOf INPUT_CLASSNAME) {
                throw new InvalidArgument(\'missing last request injected in paginated result\');
            }
            $input = clone $this->input;
            $page = $this;
            while (true) {
                if (MORE_CONDITION) {
                    SET_TOKEN_CODE
                    $this->registerPrefetch($nextPage = $client->OPERATION_NAME($input));
                } else {
                    $nextPage = null;
                }

                ITERATE_PROPERTIES_CODE

                if ($nextPage === null) {
                    break;
                }

                $this->unregisterPrefetch($nextPage);
                $page = $nextPage;
            }
        ', [
            'CLIENT_CLASSNAME' => $clientClass->getName(),
            'INPUT_CLASSNAME' => $inputClass->getName(),
            'ITERATE_PROPERTIES_CODE' => $iterator,
            'MORE_CONDITION' => $moreCondition,
            'SET_TOKEN_CODE' => $setter,
            'OPERATION_NAME' => $operation->getName(),
        ]);
    }
}
