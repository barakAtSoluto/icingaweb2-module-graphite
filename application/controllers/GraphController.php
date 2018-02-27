<?php

namespace Icinga\Module\Graphite\Controllers;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Graphite\Graphing\GraphingTrait;
use Icinga\Module\Graphite\Web\Controller\MonitoringAwareController;
use Icinga\Module\Graphite\Web\Widget\Graphs;
use Icinga\Web\UrlParams;

class GraphController extends MonitoringAwareController
{
    use GraphingTrait;

    /**
     * The URL parameters for the graph
     *
     * @var string[]
     */
    protected $graphParamsNames = ['start', 'end', 'width', 'height', 'legend', 'template', 'cachebuster'];

    /**
     * The URL parameters for metrics filtering
     *
     * @var UrlParams
     */
    protected $filterParams;

    /**
     * The URL parameters for the graph
     *
     * @var string[]
     */
    protected $graphParams = [];

    public function init()
    {
        parent::init();

        $this->filterParams = clone $this->getRequest()->getUrl()->getParams();

        foreach ($this->graphParamsNames as $paramName) {
            $this->graphParams[$paramName] = $this->filterParams->shift($paramName);
        }
    }

    public function hostAction()
    {
        $checkCommandColumn = '_host_' . Graphs::getObscuredCheckCommandCustomVar();
        $host = $this->applyMonitoringRestriction(
            $this->backend->select()->from('hoststatus', ['host_check_command', $checkCommandColumn])
        )
            ->where('host_name', $this->filterParams->getRequired('host.name'))
            ->limit(1) // just to be sure to save a few CPU cycles
            ->fetchRow();

        if ($host === false) {
            throw new HttpNotFoundException('%s', $this->translate('No such host'));
        }

        $this->supplyImage($host->host_check_command, $host->$checkCommandColumn);
    }

    public function serviceAction()
    {
        $checkCommandColumn = '_service_' . Graphs::getObscuredCheckCommandCustomVar();
        $service = $this->applyMonitoringRestriction(
            $this->backend->select()->from('servicestatus', ['service_check_command', $checkCommandColumn])
        )
            ->where('host_name', $this->filterParams->getRequired('host.name'))
            ->where('service_description', $this->filterParams->getRequired('service.name'))
            ->limit(1) // just to be sure to save a few CPU cycles
            ->fetchRow();

        if ($service === false) {
            throw new HttpNotFoundException('%s', $this->translate('No such service'));
        }

        $this->supplyImage($service->service_check_command, $service->$checkCommandColumn);
    }

    /**
     * Do all monitored object type independend actions
     *
     * @param   string      $checkCommand           The check command of the monitored object we supply an image for
     * @param   string|null $obscuredCheckCommand   The "real" check command (if any) of the monitored object
     *                                              we display graphs for
     */
    protected function supplyImage($checkCommand, $obscuredCheckCommand)
    {
        $templates = $this->getAllTemplates()->getTemplates(
            $obscuredCheckCommand === null ? $checkCommand : $obscuredCheckCommand
        );

        if (! isset($templates[$this->graphParams['template']])) {
            throw new HttpNotFoundException($this->translate('No such template'));
        }

        $charts = $templates[$this->graphParams['template']]->getCharts(
            static::getMetricsDataSource(),
            array_map('rawurldecode', $this->filterParams->toArray(false)),
            $checkCommand
        );

        switch (count($charts)) {
            case 0:
                throw new HttpNotFoundException($this->translate('No such graph'));

            case 1:
                $charts[0]
                    ->setFrom($this->graphParams['start'])
                    ->setUntil($this->graphParams['end'])
                    ->setWidth($this->graphParams['width'])
                    ->setHeight($this->graphParams['height'])
                    ->setShowLegend((bool) $this->graphParams['legend'])
                    ->serveImage($this->getResponse());

            default:
                throw new HttpBadRequestException('%s', $this->translate(
                    'Graphite Web yields more than one metric for the given filter.'
                    . ' Please specify a more precise filter.'
                ));
        }
    }
}
