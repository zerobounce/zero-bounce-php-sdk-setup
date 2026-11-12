<?php

namespace ZeroBounce\Tests;

use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZeroBounce;

/**
 * Every public request method of ZeroBounce, called once (twice where a
 * method has a second transport, such as usePost or stream). Shared by the
 * sweeps over every request path; requestMethodNames() lets each sweep check
 * that no public method is missing from it.
 */
trait RequestSweep
{
    /**
     * @return string[] the public request methods of ZeroBounce
     */
    private function requestMethodNames()
    {
        $names = array();
        foreach ((new \ReflectionClass(ZeroBounce::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && strpos($method->getName(), '__') !== 0 && $method->getName() !== 'initialize') {
                $names[] = $method->getName();
            }
        }
        return $names;
    }

    private function quietly(callable $call)
    {
        set_error_handler(function ($level) {
            return $level === E_USER_DEPRECATED;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<string, callable[]>
     */
    private function sweepCalls(ZeroBounce $c, $out)
    {
        $csv = __DIR__ . '/email_file.csv';
        $done = 'https://example.com/done';
        $post = new ZBValidateOptions();
        $post->usePost = true;
        $stream = new ZBGetFileOptions();
        $stream->stream = true;
        $job = array('file_name' => 'f.csv', 'return_url' => $done);
        return array(
            'validate' => array(
                function () use ($c) { $c->validate('a@example.com'); },
                function () use ($c, $post) { $c->validate('a@example.com', null, $post); },
            ),
            'validateBatch' => array(function () use ($c) { $c->validateBatch(array('a@example.com')); }),
            'getCredits' => array(
                function () use ($c) { $c->getCredits(); },
                function () use ($c) { $c->getCredits(true); },
            ),
            'getApiUsage' => array(
                function () use ($c) { $c->getApiUsage('2024-01-01', '2024-01-02'); },
                function () use ($c) { $c->getApiUsage('2024-01-01', '2024-01-02', true); },
            ),
            'getActivity' => array(
                function () use ($c) { $c->getActivity('a@example.com'); },
                function () use ($c) { $c->getActivity('a@example.com', true); },
            ),
            'scoreEmail' => array(
                function () use ($c) { $c->scoreEmail('a@example.com'); },
                function () use ($c) { $c->scoreEmail('a@example.com', true); },
            ),
            'scoreEmailPost' => array(function () use ($c) { $c->scoreEmailPost('a@example.com'); }),
            'sendFile' => array(function () use ($c, $csv) { $c->sendFile($csv, 1); }),
            'scoringSendFile' => array(function () use ($c, $csv) { $c->scoringSendFile($csv, 1); }),
            'sendFileStream' => array(function () use ($c) { $c->sendFileStream("e\n", 'f.csv', 1); }),
            'scoringSendFileStream' => array(function () use ($c) { $c->scoringSendFileStream("e\n", 'f.csv', 1); }),
            'fileStatus' => array(function () use ($c) { $c->fileStatus('f1'); }),
            'scoringFileStatus' => array(function () use ($c) { $c->scoringFileStatus('f1'); }),
            'getFile' => array(
                function () use ($c, $out) { $c->getFile('f1', $out); },
                function () use ($c, $out, $stream) { $c->getFile('f1', $out, $stream); },
            ),
            'scoringGetFile' => array(
                function () use ($c, $out) { $c->scoringGetFile('f1', $out); },
                function () use ($c, $out, $stream) { $c->scoringGetFile('f1', $out, $stream); },
            ),
            'deleteFile' => array(function () use ($c) { $c->deleteFile('f1'); }),
            'scoringDeleteFile' => array(function () use ($c) { $c->scoringDeleteFile('f1'); }),
            'guessFormat' => array(function () use ($c) {
                $this->quietly(function () use ($c) { $c->guessFormat('example.com', null, null, null); });
            }),
            'findEmail' => array(
                function () use ($c) { $c->findEmail('example.com', 'Ann'); },
                function () use ($c) { $c->findEmail('example.com', 'Ann', null, null, null, true); },
            ),
            'findEmailFormat' => array(
                function () use ($c) { $c->findEmailFormat('example.com'); },
                function () use ($c) { $c->findEmailFormat('example.com', null, true); },
            ),
            'sendEmailFinderFile' => array(function () use ($c, $csv) { $c->sendEmailFinderFile($csv, 1, 2); }),
            'sendEmailFinderFileStream' => array(function () use ($c) { $c->sendEmailFinderFileStream("e\n", 'f.csv', 1, 2); }),
            'emailFinderFileStatus' => array(function () use ($c) { $c->emailFinderFileStatus('f1'); }),
            'emailFinderGetFile' => array(function () use ($c, $out) { $c->emailFinderGetFile('f1', $out); }),
            'emailFinderDeleteFile' => array(function () use ($c) { $c->emailFinderDeleteFile('f1'); }),
            'sendDomainSearchFile' => array(function () use ($c, $csv) { $c->sendDomainSearchFile($csv, 1); }),
            'sendDomainSearchFileStream' => array(function () use ($c) { $c->sendDomainSearchFileStream("d\n", 'f.csv', 1); }),
            'domainSearchFileStatus' => array(function () use ($c) { $c->domainSearchFileStatus('f1'); }),
            'domainSearchGetFile' => array(function () use ($c, $out) { $c->domainSearchGetFile('f1', $out); }),
            'domainSearchDeleteFile' => array(function () use ($c) { $c->domainSearchDeleteFile('f1'); }),
            'sendListEvaluatorFile' => array(function () use ($c, $csv) { $c->sendListEvaluatorFile($csv, 1); }),
            'sendListEvaluatorFileStream' => array(function () use ($c) { $c->sendListEvaluatorFileStream("e\n", 'f.csv', 1); }),
            'getListEvaluatorResult' => array(function () use ($c) { $c->getListEvaluatorResult('f1'); }),
            'sendEvaluatedFile' => array(function () use ($c) { $c->sendEvaluatedFile('f1'); }),
            'listS3Files' => array(function () use ($c) { $c->listS3Files(); }),
            'validateS3File' => array(function () use ($c, $job) { $c->validateS3File($job); }),
            'scoreS3File' => array(function () use ($c, $job) { $c->scoreS3File($job); }),
            'exportS3File' => array(function () use ($c) { $c->exportS3File('f1'); }),
            'addFilter' => array(function () use ($c) { $c->addFilter('allow', 'email', 'a@example.com'); }),
            'listFilters' => array(
                function () use ($c) { $c->listFilters(); },
                function () use ($c) { $c->listFilters(true); },
            ),
            'deleteFilter' => array(function () use ($c) { $c->deleteFilter('allow', 'email', 'a@example.com'); }),
            'emailFinderS3File' => array(function () use ($c, $job) { $c->emailFinderS3File($job); }),
            'domainSearchS3File' => array(function () use ($c, $job) { $c->domainSearchS3File($job); }),
            'validateSFTPFile' => array(function () use ($c, $job) { $c->validateSFTPFile($job); }),
            'scoreSFTPFile' => array(function () use ($c, $job) { $c->scoreSFTPFile($job); }),
            'emailFinderSFTPFile' => array(function () use ($c, $job) { $c->emailFinderSFTPFile($job); }),
            'domainSearchSFTPFile' => array(function () use ($c, $job) { $c->domainSearchSFTPFile($job); }),
            'exportSFTPFile' => array(function () use ($c) { $c->exportSFTPFile('f1'); }),
            'getInboxPlacementProviders' => array(function () use ($c) { $c->getInboxPlacementProviders(); }),
            'getInboxPlacementTestTypes' => array(function () use ($c) { $c->getInboxPlacementTestTypes(); }),
            'createInboxPlacementTest' => array(function () use ($c) { $c->createInboxPlacementTest('Spring launch'); }),
            'getInboxPlacementTest' => array(function () use ($c) { $c->getInboxPlacementTest(1); }),
            'listInboxPlacementTests' => array(function () use ($c) { $c->listInboxPlacementTests(); }),
            'deleteInboxPlacementTest' => array(function () use ($c) { $c->deleteInboxPlacementTest(1); }),
            'getInboxPlacementWebhook' => array(function () use ($c) { $c->getInboxPlacementWebhook(); }),
            'setInboxPlacementWebhook' => array(function () use ($c) { $c->setInboxPlacementWebhook('https://example.com/h'); }),
        );
    }
}
