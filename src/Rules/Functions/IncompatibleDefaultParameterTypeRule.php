<?php declare(strict_types = 1);

namespace PHPStan\Rules\Functions;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Generic\TemplateTypeHelper;
use PHPStan\Type\VerbosityLevel;

/**
 * @implements \PHPStan\Rules\Rule<\PhpParser\Node\FunctionLike>
 */
class IncompatibleDefaultParameterTypeRule implements Rule
{

	private \PHPStan\Reflection\ReflectionProvider $reflectionProvider;

	public function __construct(ReflectionProvider $reflectionProvider)
	{
		$this->reflectionProvider = $reflectionProvider;
	}

	public function getNodeType(): string
	{
		return FunctionLike::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!($node instanceof Function_)) {
			return [];
		}

		$name = $node->namespacedName;
		if (!$this->reflectionProvider->hasFunction($name, $scope)) {
			return [];
		}

		$function = $this->reflectionProvider->getFunction($name, $scope);
		$parameters = ParametersAcceptorSelector::selectSingle($function->getVariants());

		$errors = [];
		foreach ($node->getParams() as $paramI => $param) {
			if ($param->default === null) {
				continue;
			}
			if (
				$param->var instanceof Node\Expr\Error
				|| !is_string($param->var->name)
			) {
				throw new \PHPStan\ShouldNotHappenException();
			}

			$defaultValueType = $scope->getType($param->default);
			$parameterType = $parameters->getParameters()[$paramI]->getType();
			$parameterType = TemplateTypeHelper::resolveToBounds($parameterType);

			if ($parameterType->accepts($defaultValueType, true)->yes()) {
				continue;
			}

			$verbosityLevel = VerbosityLevel::getRecommendedLevelByType($parameterType);

			$errors[] = RuleErrorBuilder::message(sprintf(
				'Default value of the parameter #%d $%s (%s) of function %s() is incompatible with type %s.',
				$paramI + 1,
				$param->var->name,
				$defaultValueType->describe($verbosityLevel),
				$function->getName(),
				$parameterType->describe($verbosityLevel)
			))->line($param->getLine())->build();
		}

		return $errors;
	}

}
