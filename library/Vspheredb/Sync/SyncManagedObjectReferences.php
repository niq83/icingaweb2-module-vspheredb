<?php

namespace Icinga\Module\Vspheredb\Sync;

use Icinga\Application\Logger;
use Icinga\Module\Vspheredb\DbObject\ManagedObject;
use Icinga\Module\Vspheredb\DbObject\VCenter;
use Icinga\Module\Vspheredb\SelectSet\FullSelectSet;
use Icinga\Module\Vspheredb\Util;

class SyncManagedObjectReferences
{
    /** @var VCenter */
    private $vCenter;

    /**
     * SyncManagedObjectReferences constructor.
     * @param VCenter $vCenter
     */
    public function __construct(VCenter $vCenter)
    {
        $this->vCenter = $vCenter;
    }

    /**
     * @return $this
     * @throws \Icinga\Module\Director\Exception\DuplicateKeyException
     * @throws \Zend_Db_Adapter_Exception
     */
    public function sync()
    {
        $vCenter = $this->vCenter;
        Logger::debug('Ready to fetch id/name/parent list');
        $all = $this->fetchNames();
        Logger::debug('Got id/name/parent for %d objects', count($all));
        $db = $this->vCenter->getConnection();
        $triggeredAlarms = [];
        //$declaredAlarms = [];
        /** @var ManagedObject[] $objects */
        $objects = ManagedObject::loadAllForVCenter($vCenter);
        $fetched = [];
        $nameUuids = [];
        $idToParent = [];
        $vCenterUuid = $vCenter->get('uuid');
        foreach ($all as $obj) {
            foreach ($obj->triggeredAlarmState as $alarms) {
                foreach ($alarms as $alarm) {
                    $triggeredAlarms[$alarm->key] = $alarm;
                }
            }
            /*
            foreach ($obj->declaredAlarmState as $alarms) {
                foreach ($alarms as $alarm) {
                    $declaredAlarms[] = $alarm;
                }
            }
            */
            $moRef = $obj->id;
            $name = $obj->name;
            $uuid = $vCenter->makeBinaryGlobalUuid($moRef);
            $fetched[$uuid] = $name;
            $nameUuids[$moRef] = $uuid;
            if (array_key_exists($uuid, $objects)) {
                $object = $objects[$uuid];
                $object->set('moref', $moRef);
                $object->set('vcenter_uuid', $vCenterUuid);
                $object->set('object_name', $name);
                $object->set('object_type', $obj->type);
                $object->set('overall_status', $obj->overallStatus);
            } else {
                $objects[$uuid] = ManagedObject::create([
                    'uuid'           => $uuid,
                    'vcenter_uuid'   => $vCenterUuid,
                    'moref'          => $moRef,
                    'object_name'    => $name,
                    'object_type'    => $obj->type,
                    'overall_status' => $obj->overallStatus,
                ], $db);
            }
            if (property_exists($obj, 'parent')) {
                $idToParent[$uuid] = $obj->parent->_;
            }
        }

        foreach ($idToParent as $uuid => $parentName) {
            if (array_key_exists($parentName, $nameUuids)) {
                $objects[$uuid]->setParent(
                    $objects[$nameUuids[$parentName]]
                );
            } else {
                Logger::error(
                    "Could not find parent $parentName for %s",
                    $fetched[$uuid]
                );
            }
        }

        Logger::debug('Storing object tree to DB');
        $dba = $this->vCenter->getDb();
        $dba->beginTransaction();
        $new = $same = $del = $mod = [];
        foreach ($objects as $uuid => $object) {
            $name = $object->get('object_name');
            if ($object->hasBeenLoadedFromDb()) {
                if ($object->hasBeenModified()) {
                    $mod[$uuid] = $name;
                } elseif (array_key_exists($uuid, $fetched)) {
                    $same[$uuid] = $name;
                } else {
                    $del[$uuid] = $name;
                }
            } else {
                $new[$uuid] = $name;
            }
        }


        $dba->delete(
            'triggered_alarm',
            $dba->quoteInto('vcenter_uuid = ?', $vCenterUuid)
        );
        foreach ($triggeredAlarms as $alarm) {
            $dba->insert('triggered_alarm', [
                'entity_uuid'    => $vCenter->makeBinaryGlobalUuid($alarm->entity),
                'alarm_uuid'     => $vCenter->makeBinaryGlobalUuid($alarm->alarm),
                'vcenter_uuid'   => $vCenterUuid,
                'overall_status' => $alarm->overallStatus,
                'ts_created'     => Util::timeStringToUnixMs($alarm->time),
                // acknowledged
            ]);
        }

        /*
        // Debug only:
        printf("%d new: %s\n", count($new), implode(', ', $new));
        printf("%d mod: %s\n", count($mod), implode(', ', $mod));
        foreach ($mod as $id => $name) {
            printf("%s has been modified:\n", $name);
            foreach ($objects[$id]->getModifiedProperties() as $prop => $newVal) {
                printf(
                    "%s changed from %s to %s\n",
                    $prop,
                    $objects[$id]->getOriginalProperty($prop),
                    $newVal
                );
            }
        }
        printf("%d del: %s\n", count($del), implode(', ', $del));
        printf("%d unmodified\n", count($same));
        */
        // print_r($declaredAlarms);

        if (! empty($del)) {
            $dba->update(
                'object',
                ['parent_uuid' => null],
                $dba->quoteInto('parent_uuid IN (?)', array_keys($del))
            );
            $dba->delete(
                'object',
                $dba->quoteInto('uuid IN (?)', array_keys($del))
            );
        }

        foreach ($objects as $object) {
            $object->store();
        }
        $dba->commit();
        Logger::debug('Committed %d objects', count($objects));

        return $this;
    }

    protected function fetchNames()
    {
        return $this->vCenter->getApi()->propertyCollector()->collectProperties(
            $this->prepareNameSpecSet()
        );
    }

    protected function prepareNameSpecSet()
    {
        $types = [
            'Datacenter',
            'Datastore',
            'Folder',
            'ResourcePool',
            'HostSystem',
            'ComputeResource',
            'ClusterComputeResource',
            'StoragePod',
            'VirtualMachine',
            'VirtualApp',
            'Network',
            'DistributedVirtualSwitch',
            'DistributedVirtualPortgroup',
        ];
        $pathSet = [
            'name',
            'parent',
            'overallStatus',
            // 'declaredAlarmState',
            'triggeredAlarmState'
        ];

        $propSet = [];
        foreach ($types as $type) {
            $propSet[] = [
                'type' => $type,
                'all' => 0,
                'pathSet' => $pathSet
            ];
        }

        return [
            'propSet' => $propSet,
            'objectSet' => [
                'obj'  => $this->vCenter->getApi()->getServiceInstance()->rootFolder,
                'skip' => false,
                'selectSet' => (new FullSelectSet())->toArray(),
            ]
        ];
    }
}
