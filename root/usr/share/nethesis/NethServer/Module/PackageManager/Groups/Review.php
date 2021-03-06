<?php
namespace NethServer\Module\PackageManager\Groups;

/*
 * Copyright (C) 2013 Nethesis S.r.l.
 *
 * This script is part of NethServer.
 *
 * NethServer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * NethServer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with NethServer.  If not, see <http://www.gnu.org/licenses/>.
 */

use Nethgui\System\PlatformInterface as Validate;

/**
 * Review the groups selection, choosing additional packages to install
 *
 * @author Davide Principi <davide.principi@nethesis.it>
 * @since 1.0
 */
class Review extends \Nethgui\Controller\Collection\AbstractAction
{
    /**
     *
     * @var string
     */
    private $taskIdentifier;

    public function initialize()
    {
        parent::initialize();
        $this->declareParameter('addGroups', Validate::ANYTHING);
        $this->declareParameter('removeGroups', Validate::ANYTHING);
        $this->declareParameter('optionals', Validate::ANYTHING_COLLECTION);      
    }

    public function validate(\Nethgui\Controller\ValidationReportInterface $report)
    {
        parent::validate($report);
        if ($this->getRequest()->isMutation()) {
            $this->validateGroups($report, 'addGroups');
            $this->validateGroups($report, 'removeGroups');
        }
    }

    private function validateGroups(\Nethgui\Controller\ValidationReportInterface $report, $parameter)
    {
        if (empty($this->parameters[$parameter])) {
            return;
        }

        $groups = explode(',', $this->parameters[$parameter]);
        $validGroups = array_keys(iterator_to_array($this->getAdapter()));
        foreach ($groups as $group) {
            if ( ! in_array($group, $validGroups)) {
                $report->addValidationErrorMessage($this, $parameter, sprintf('Invalid group %s', $group));
                break;
            }
        }
    }

    public function getInstalledRpms()
    {
        $rpms = array();

        $process = $this->getPlatform()->exec("/bin/rpm -qa --queryformat '%{NAME}\n'");
        if ($process->getExitCode() === 0) {
            foreach ($process->getOutputArray() as $line) {
                $rpms[] = trim($line, "\n");
            }
        } else {
            $this->getLog()->error($process->getOutput());
        }

        return $rpms;
    }

    public function process()
    {
        parent::process();
        if ($this->getRequest()->isMutation()) {
            // Destroy the session storage:
            $this->getPlatform()->getDatabase('SESSION')->deleteKey(get_class($this->getParent()));

            $arguments = array();

            $prependAt = function($n) {
                return '@' . $n;
            };

            if ($this->parameters['addGroups']) {
                $arguments[] = '--install';
                $arguments[] = implode(',', array_map($prependAt, explode(',', $this->parameters['addGroups'])));
            }

            // Resolve the list of groups to remove into an RPM list:
            if ($this->parameters['removeGroups']) {
                $arguments[] = '--remove';
                $arguments[] = implode(',', $this->calculateRpmsToRemove(explode(',', $this->parameters['removeGroups'])));
            }

            // Add "default" packages, to honorate package classification from
            // Fedora: http://fedoraproject.org/wiki/How_to_use_and_edit_comps.xml_for_package_groups
            $selectedDefaults = $this->calculateDefaultRpms();
            if (count($selectedDefaults) > 0) {
                $arguments[] = '--install';
                $arguments[] = implode(',', $selectedDefaults);
            }

            // Find selected optional packages:
            $selectedOptionals = array();
            if (is_array($this->parameters['optionals'])) {
                foreach ($this->parameters['optionals'] as $rpm => $element) {
                    if ($element['status'] === 'installed') {
                        $selectedOptionals[] = $rpm;
                    }
                }
            }
            if (count($selectedOptionals) > 0) {
                $arguments[] = '--install';
                $arguments[] = implode(',', $selectedOptionals);
            }

            if(count($arguments) === 0) {
                throw new \RuntimeException(sprintf("%s: package action was not specified", __CLASS__), 1395154356);
            }
            $this->taskIdentifier = $this->getPlatform()->exec('/usr/bin/sudo /sbin/e-smith/pkgaction ${@}', $arguments, TRUE)->getIdentifier();            
        }
    }

    private function calculateDefaultRpms()
    {
        $defaultRpms = array();

        foreach ($this->getAdapter() as $group) {
            $defaultRpms = array_merge($defaultRpms, $group['dpackages']);
        }

        return $defaultRpms;
    }

    /**
     * To remove the given list of $rgroups, search for all the mandatory RPMs
     * that are no longer required by the remaining package groups.
     *
     * @param array $rgroups
     * @return array
     */
    private function calculateRpmsToRemove($rgroups)
    {
        $rpms = array();

        foreach ($this->getAdapter() as $group) {
            $action = 0;
            if (in_array($group['id'], $rgroups)) {
                $action |= 0x02; // group to remove
            } else {
                $action |= 0x01; // group to keep
            }

            // mark all the mandatory packages:
            foreach ($group['mpackages'] as $rpm) {
                if ( ! isset($rpms[$rpm])) {
                    $rpms[$rpm] = 0;
                }
                $rpms[$rpm] |= $action;
            }
        }

        $removeList = array();

        foreach ($rpms as $rpm => $action) {
            if ($action === 0x02) {
                $removeList[] = $rpm;
            }
        }

        return $removeList;
    }

    private function getSelection()
    {
        $key = get_class($this->getParent());
        $sessionDb = $this->getPlatform()->getDatabase('SESSION');
        return array(
            'add' => is_array($sessionDb->getProp($key, 'add')) ? $sessionDb->getProp($key, 'add') : array(),
            'remove' => is_array($sessionDb->getProp($key, 'remove')) ? $sessionDb->getProp($key, 'remove') : array(),
            'keep' => is_array($sessionDb->getProp($key, 'keep')) ? $sessionDb->getProp($key, 'keep') : array()
        );
    }

    public function prepareView(\Nethgui\View\ViewInterface $view)
    {
        parent::prepareView($view);

        $view['Back'] = $view->getModuleUrl('../Select');

        if ( ! $this->getRequest()->isMutation()) {
            $selection = $this->getSelection();
            $view['optionals'] = $this->getOptionalRpms($selection);
            $view['messages'] = $this->getMessagesText($view->getTranslator(), $selection);
            $view['addGroups'] = implode(',', $selection['add']);
            $view['removeGroups'] = implode(',', $selection['remove']);
        } elseif ($this->getRequest()->isValidated() && $this->getRequest()->isMutation()) {
            // FIXME EXPERIMENTAL
            $view->getCommandList()
                ->httpHeader('HTTP/1.1 202 Accepted')
                ->sendQuery($view->getModuleUrl('../Tracker/' . $this->taskIdentifier), 1000, TRUE)
            ;
        }
    }

    private function getOptionalRpms($selection)
    {
        $installedRpms = $this->getInstalledRpms();
        $optionals = array();

        // Generate the list of optional packages:
        foreach ($this->getAdapter() as $group) {

            // skip unselected groups:
            if ($group['status'] === 'available' && ! in_array($group['id'], $selection['add'])) {
                continue;
            }

            foreach ($group['opackages'] as $pkg) {

                if (in_array($pkg, $installedRpms)) {
                    continue;
                }

                if ( ! isset($optionals[$pkg])) {
                    $optionals[$pkg] = array(
                        'id' => $pkg,
                        'requiredBy' => array(),
                        'status' => 'available'
                    );
                }

                $optionals[$pkg]['requiredBy'][] = $group['name'];
            }
        }

        return array_values($optionals);
    }

    private function getMessagesText(\Nethgui\View\TranslatorInterface $t, $selection)
    {
        $messages = array();

        /* @var \ArrayObject */
        $adapter = $this->getAdapter();

        $mapGroupName = function($k) use ($adapter) {
            return isset($adapter[$k]) ? $adapter[$k]['name'] : $k;
        };

        if ( ! empty($selection['add'])) {
            $messages[] = $t->translate($this, 'GroupsToAdd_label', array(count($selection['add']),
                implode(', ', array_map($mapGroupName, $selection['add']))));
        }

        if ( ! empty($selection['remove'])) {
            $messages[] = $t->translate($this, 'GroupsToRemove_label', array(count($selection['remove']),
                implode(', ', array_map($mapGroupName, $selection['remove']))));
        }

        return $messages;
    }

    public function nextPath()
    {
        if ($this->getRequest()->isMutation()) {
            return 'Tracker';
        }
        return parent::nextPath();
    }

}
