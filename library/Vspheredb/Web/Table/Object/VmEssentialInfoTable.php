<?php

namespace Icinga\Module\Vspheredb\Web\Table\Object;

use gipfl\IcingaWeb2\Icon;
use gipfl\IcingaWeb2\Link;
use gipfl\Translation\TranslationHelper;
use gipfl\IcingaWeb2\Widget\NameValueTable;
use Exception;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Vspheredb\Addon\BackupTool;
use Icinga\Module\Vspheredb\Addon\IbmSpectrumProtect;
use Icinga\Module\Vspheredb\Addon\VeeamBackup;
use Icinga\Module\Vspheredb\Addon\VRangerBackup;
use Icinga\Module\Vspheredb\DbObject\MonitoringConnection;
use Icinga\Module\Vspheredb\DbObject\VCenter;
use Icinga\Module\Vspheredb\DbObject\VirtualMachine;
use Icinga\Module\Vspheredb\EventHistory\VmRecentMigrationHistory;
use Icinga\Module\Vspheredb\PathLookup;
use Icinga\Module\Vspheredb\Web\Widget\IcingaHostStatusRenderer;
use ipl\Html\Html;

class VmEssentialInfoTable extends NameValueTable
{
    use TranslationHelper;

    /** @var VirtualMachine */
    protected $vm;

    /** @var VCenter */
    protected $vCenter;

    public function __construct(VirtualMachine $vm)
    {
        $this->vm = $vm;
        $this->vCenter = VCenter::load($vm->get('vcenter_uuid'), $vm->getConnection());
    }

    protected function getDb()
    {
        return $this->vm->getConnection();
    }

    /**
     * @param $annotation
     * @return string|\ipl\Html\HtmlElement
     */
    protected function formatAnnotation($annotation)
    {
        $tools = [
            new IbmSpectrumProtect(),
            new VeeamBackup(),
            new VRangerBackup(),
        ];
        /** @var BackupTool $tool */
        foreach ($tools as $tool) {
            $tool->stripAnnotation($annotation);
        }

        $annotation = trim($annotation);

        if (strpos($annotation, "\n") === false) {
            return $annotation;
        } else {
            return Html::tag('pre', null, $annotation);
        }
    }

    /**
     * @throws \Icinga\Exception\NotFoundError
     */
    protected function assemble()
    {
        $vm = $this->vm;
        $uuid = $vm->get('uuid');
        if ($annotation = $vm->get('annotation')) {
            $this->addNameValueRow(
                $this->translate('Annotation'),
                $this->formatAnnotation($annotation)
            );
        }

        /** @var \Icinga\Module\Vspheredb\Db $connection */
        $connection = $vm->getConnection();
        $lookup =  new PathLookup($connection);

        if ($guestName = $vm->get('guest_full_name')) {
            $guest = sprintf(
                '%s (%s)',
                $guestName,
                $vm->get('guest_id')
            );
        } else {
            $guest = '-';
        }

        /*
        $this->addNameValueRow(
            $this->translate('Monitoring'),
            $this->getMonitoringInfo($vm)
        );
        */

        if ($vm->get('guest_id')) {
            $this->addNameValuePairs([
                $this->translate('Console') => $this->linkToVMRC(
                    $vm->object()->get('object_name'),
                    $vm->object()->get('moref')
                ),
                $this->translate('Guest OS') => $guest,
                $this->translate('Guest IP') => $vm->get('guest_ip_address') ?: '-',
                $this->translate('Guest Hostname') => $vm->get('guest_host_name') ?: '-',
                $this->translate('Guest Utilities') => [
                    sprintf(
                        '%s (Guest %s)',
                        $vm->get('guest_tools_running_status'),
                        $vm->get('guest_state')
                    ),
                ],
            ]);
        }  else {
            $this->addNameValuePairs([
                $this->translate('Guest Utilities') => sprintf(
                        '%s (Guest %s)',
                        $vm->get('guest_tools_running_status'),
                        $vm->get('guest_state')
                ),
            ]);
        }

        $this->addNameValuePairs([
            $this->translate('Host') => Html::sprintf(
                '%s (%s)',
                $lookup->linkToObject($vm->get('runtime_host_uuid')),
                $this->getConnectionStateDetails($vm->get('connection_state'))
            ),
            $this->translate('Resource Pool') => $lookup->linkToObject($vm->get('resource_pool_uuid')),
        ]);
        $migrations = new VmRecentMigrationHistory($vm);
        $cntMigrations = $migrations->countWeeklyMigrationAttempts();
        $this->addNameValueRow(
            $this->translate('Migrations'),
            Html::sprintf(
                $this->translate('%s %s took place during the last 7 days'),
                $cntMigrations,
                Link::create(
                    $this->translate('VMotion attempt(s)'),
                    'vspheredb/vm/events',
                    ['uuid' => bin2hex($uuid)]
                )
            )
        );
    }

    /**
     * @param VirtualMachine $vm
     * @return array|null
     */
    protected function getMonitoringInfo(VirtualMachine $vm)
    {
        $name = $vm->get('guest_host_name');
        $statusRenderer = new IcingaHostStatusRenderer();
        try {
            $monitoring = MonitoringConnection::eventuallyLoadForVCenter($this->vCenter);
            if ($monitoring && $monitoring->hasHost($name)) {
                $monitoringState = $monitoring->getHostState($name);
                return [
                    // TODO: is_acknowledged, is_in_downtime
                    $statusRenderer($monitoringState->current_state),
                    ' ',
                    $monitoringState->output,
                    ' ',
                    Link::create(
                        $this->translate('more'),
                        'monitoring/host/show',
                        ['host' => $name],
                        ['class' => 'icon-right-small']
                    )
                ];
            } else {
                return [Html::sprintf(
                    "There is no monitored Host mapped to this VM"
                )];
            }
        } catch (Exception $e) {
            return [
                Html::tag('p', ['class' => 'error'], sprintf(
                    $this->translate('Unable to check monitoring state: %s'),
                    $e->getMessage()
                ))
            ];
        }
    }

    protected function linkToVCenter($moRef)
    {
        try {
            $server = $this->vCenter->getFirstServer();
        } catch (NotFoundError $e) {
            return [
                Icon::create('warning-empty', [
                    'class' => 'red'
                ]),
                ' ',
                $this->translate('No related vServer has been configured')
            ];
        }
        return Html::tag('a', [
            'href' => sprintf(
                'https://%s/mob/?moid=%s',
                $server->get('host'),
                rawurlencode($moRef)
            ),
            'target' => '_blank',
            'title' => $this->translate('Jump to the Managed Object browser')
        ], $moRef);
    }

    protected function linkToVMRC($name, $moRef)
    {
        try {
            $server = $this->vCenter->getFirstServer();
        } catch (NotFoundError $e) {
            return [
                Icon::create('warning-empty', [
                    'class' => 'red'
                ]),
                ' ',
                $this->translate('No related vServer has been configured')
            ];
        }
        return Html::tag('a', [
            'href' => sprintf(
                'vmrc://%s/?moid=%s',
                $server->get('host'),
                rawurlencode($moRef)
            ),
            'target' => '_self',
            'title' => $this->translate('Open console with VMRC')
        ], $name);
    }

    protected function getConnectionStateDetails($state)
    {
        $infos = [
            'connected'    => $this->translate(
                'The server has access to the virtual machine'
            ),
            'disconnected' => $this->translate(
                'The server is currently disconnected from the virtual machine,'
                . ' since its host is disconnected'
            ),
            'inaccessible' => $this->translate(
                'One or more of the virtual machine configuration files are'
                . ' inaccessible. For example, this can be due to transient disk'
                . ' failures. In this case, no configuration can be returned for'
                . ' a virtual machine'
            ),
            'invalid' => $this->translate(
                'The virtual machine configuration format is invalid. Thus, it is'
                . ' accessible on disk, but corrupted in a way that does not allow'
                . ' the server to read the content. In this case, no configuration'
                . ' can be returned for a virtual machine.'
            ),
            'orphaned' => $this->translate(
                'The virtual machine is no longer registered on the host it is'
                . ' associated with. For example, a virtual machine that is'
                . ' unregistered or deleted directly on a host managed by'
                . ' VirtualCenter shows up in this state.'
            ),
        ];

        return $infos[$state];
    }
}
