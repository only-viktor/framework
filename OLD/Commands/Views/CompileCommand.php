<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Commands\Views;

use Spiral\Console\Command;
use Symfony\Component\Console\Helper\FormatterHelper;

/**
 * Compile every available view file and store result in view cache.
 */
class CompileCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'views:compile';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Compile every available view file.';

    /**
     * Perform command.
     */
    public function perform()
    {
        /**
         * @var FormatterHelper $formatter
         */
        $formatter = $this->getHelper('formatter');
        foreach ($this->views->getNamespaces() as $namespace => $directories) {
            if (empty($views = $this->views->getViews($namespace))) {
                continue;
            }

            $this->isVerbosity() && $this->writeln(
                "Compiling views in namespace '<comment>{$namespace}</comment>'."
            );

            foreach ($views as $view => $engine) {
                $this->isVerbosity() && $this->write($formatter->formatSection(
                    $namespace, $view . ", ", 'fg=cyan'
                ));

                $start = microtime(true);
                $this->views->compile($namespace, $view);
                $elapsed = number_format((microtime(true) - $start) * 1000);

                $this->isVerbosity() && $this->writeln("<comment>{$elapsed}</comment> ms");
            }
        }

        $this->writeln("<info>View cache was successfully generated.</info>");
    }
}