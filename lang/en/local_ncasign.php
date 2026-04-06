<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

$string['pluginname'] = 'NCA Signing';
$string['ncasign:managejobs'] = 'Manage NCA signing jobs';

$string['settingsheader'] = 'NCA signing workflow';
$string['enabled'] = 'Enable plugin';
$string['manualwindowhours'] = 'Manual signing window (hours)';
$string['autosignenabled'] = 'Enable auto-sign fallback';
$string['autosignnote'] = 'Auto-sign note';
$string['certurltemplate'] = 'Certificate URL template';
$string['ncanodeheader'] = 'NCANode verification backend';
$string['ncanodebaseurl'] = 'NCANode base URL';
$string['ncanodebaseurl_desc'] = 'Base URL for the NCANode REST service, for example http://127.0.0.1:14579.';
$string['ncanodetimeout'] = 'NCANode request timeout (seconds)';
$string['ncanodetimeout_desc'] = 'HTTP timeout for NCANode verification requests.';
$string['ncanodecheckocsp'] = 'Check OCSP in NCANode';
$string['ncanodecheckocsp_desc'] = 'Send OCSP revocation checking in the NCANode verification request.';
$string['ncanodecheckcrl'] = 'Check CRL in NCANode';
$string['ncanodecheckcrl_desc'] = 'Send CRL revocation checking in the NCANode verification request.';
$string['ncalayerekus'] = 'NCALayer signer EKU OIDs';
$string['ncalayerekus_desc'] = 'Optional comma-separated EKU OIDs used to filter certificates in the NCALayer basics signing dialog. Leave empty for test certificates or mixed key sets. When values are provided, the standard signing EKU 1.3.6.1.5.5.7.3.4 is added automatically.';
$string['ncalayerusetsa'] = 'Request TSA in NCALayer';
$string['ncalayerusetsa_desc'] = 'When enabled, the NCALayer basics signing request includes tsaProfile: {}. Disable this for troubleshooting or for test environments where timestamping causes client-side signing failures.';
$string['padesheader'] = 'PAdES finalization backend';
$string['padesfinalizerbackend'] = 'PAdES finalizer backend';
$string['padesfinalizerbackend_desc'] = 'Select which backend should finalize PDFs after detached CMS verification. Use the Java sidecar only when the external embedder service is deployed.';
$string['padesbackendartifact'] = 'Artifact PDF fallback';
$string['padesbackendjavasidecar'] = 'Java sidecar embedder';
$string['padesembedderbaseurl'] = 'Java sidecar base URL';
$string['padesembedderbaseurl_desc'] = 'Base URL for the Java PAdES sidecar service, for example http://127.0.0.1:18080.';
$string['padesembeddertimeout'] = 'Java sidecar timeout (seconds)';
$string['padesembeddertimeout_desc'] = 'HTTP timeout for detached CMS to embedded PDF finalization requests.';

$string['taskprocessjobs'] = 'Process overdue NCA sign jobs';

$string['jobs'] = 'Signing jobs';
$string['jobdetails'] = 'Job details';
$string['viewdetails'] = 'View details';
$string['backtojobs'] = 'Back to jobs';
$string['jobsummary'] = 'Job summary';
$string['jobidlabel'] = 'Job ID';
$string['templateprofile'] = 'Template profile';
$string['finalizerbackendlabel'] = 'Finalizer backend';
$string['signaturemanifestlabel'] = 'Signature manifest';
$string['finalizationevidencelabel'] = 'Finalization evidence';
$string['jobdrafthash'] = 'Draft SHA-256';
$string['jobfinalhash'] = 'Final SHA-256';
$string['signerorder'] = 'Order';
$string['signername'] = 'Signer name';
$string['signeremail'] = 'Signer email';
$string['expectediinlabel'] = 'Expected IIN';
$string['actualiinlabel'] = 'Verified IIN';
$string['signedatlabel'] = 'Signed at';
$string['verificationstatuslabel'] = 'Verification status';
$string['signingmethodlabel'] = 'Signing method';
$string['verificationdetails'] = 'Verification details';
$string['badgepending'] = 'Pending';
$string['badgepartial'] = 'Partially signed ({$a})';
$string['badgecompletedmanual'] = 'Completed';
$string['badgecompletedauto'] = 'Completed by auto-sign';
$string['badgefinalizefailed'] = 'Finalize failed';
$string['badgesigned'] = 'Signed';
$string['badgeskipped'] = 'Skipped by auto-sign';
$string['badgewaitingprevious'] = 'Waiting for previous signer';
$string['badgeawaitingsignature'] = 'Awaiting signature';
$string['badgeverified'] = 'Verified';
$string['badgedeferredpades'] = 'Accepted: pending PDF finalization';
$string['badgerevoked'] = 'Rejected: revoked cert';
$string['badgetrusterror'] = 'Rejected: trust-chain error';
$string['badgeiinmismatch'] = 'Rejected: IIN mismatch';
$string['certsubjectlabel'] = 'Certificate subject';
$string['certseriallabel'] = 'Certificate serial';
$string['certkeyusagelabel'] = 'Certificate key usage';
$string['certperiodlabel'] = 'Certificate validity';
$string['payloadsha256label'] = 'Payload SHA-256';
$string['cmssha256label'] = 'CMS SHA-256';
$string['signingtimelabel'] = 'Signing time';
$string['verifyinfolabel'] = 'Verifier message';
$string['revocationslabel'] = 'Revocation checks';
$string['createdemojob'] = 'Create demo job';
$string['templateprofiles'] = 'Template profiles';
$string['templateprofileadd'] = 'Add template profile';
$string['templateprofileedit'] = 'Edit template profile';
$string['templateprofilesback'] = 'Back to template profiles';
$string['templateprofilesempty'] = 'No template profiles have been configured yet.';
$string['templateprofilenotice_saved'] = 'Template profile saved.';
$string['templateprofilenotice_deleted'] = 'Template profile deleted.';
$string['templaterenderer'] = 'Template renderer';
$string['templaterenderer_desc'] = 'Renderer family used to generate this document. For now only the engineer protocol renderer is implemented.';
$string['templatepathlabel'] = 'Template PDF path';
$string['templatepathlabel_desc'] = 'Absolute server path to the source PDF for this profile. The finalized client .docx must be converted to PDF and referenced here.';
$string['templatecourses'] = 'Mapped course IDs';
$string['templatecourses_desc'] = 'Comma-separated Moodle course IDs that should generate this template on completion.';
$string['templatesigners'] = 'Signer sequence';
$string['templatesigners_desc'] = 'One signer per line in the format: email|display name|position|expected IIN. The IIN is optional but recommended for server-side signer verification.';
$string['templatelayoutconfig'] = 'Layout config (JSON)';
$string['templatelayoutconfig_desc'] = 'Per-template coordinate and field mapping JSON. For the finalized BiOT protocol template this stores overlay positions and manual metadata such as order reference and protocol type labels.';
$string['templateclientcompanyoverride'] = 'Client company override';
$string['templateclientcompanyoverride_desc'] = 'Optional fixed company name to print in the protocol header and organisation column. Leave empty to resolve the user company from IOMAD company membership.';
$string['templatesentalcompany'] = 'Commission company name';
$string['templatesentalcompany_desc'] = 'Legal company name appended to chair/member lines, for example ТОО "SENTAL".';
$string['templateorderdate'] = 'Order date';
$string['templateorderdate_desc'] = 'Administrative order date used in the bilingual commission-reference sentence.';
$string['templateordernumber'] = 'Order number';
$string['templateordernumber_desc'] = 'Administrative order number, for example №-2025-03.';
$string['templateprotocoltypeinitialkz'] = 'Initial protocol type (KZ)';
$string['templateprotocoltypeinitialru'] = 'Initial protocol type (RU)';
$string['templateprotocoltyperepeatkz'] = 'Repeat protocol type (KZ)';
$string['templateprotocoltyperepeaturu'] = 'Repeat protocol type (RU)';
$string['templatestatuspassed'] = 'Passed status text';
$string['templatestatusfailed'] = 'Failed status text';
$string['templateactive'] = 'Active';
$string['templateprofilelayoutinvalid'] = 'Layout config must be valid JSON.';
$string['courseid'] = 'Course ID';
$string['userid'] = 'User ID (student)';
$string['signeremails'] = 'Signer emails (comma separated)';
$string['createjob'] = 'Create job';
$string['status'] = 'Status';
$string['deadline'] = 'Manual deadline';
$string['manualsigned'] = 'Manual signatures';
$string['autosigned'] = 'Auto-signed';
$string['artifacts'] = 'Artifacts';
$string['finalizationnotelabel'] = 'Finalization note';
$string['documenttitle'] = 'Document title';
$string['documenttype'] = 'Document type';

$string['signtitle'] = 'Certificate signature request';
$string['signinstructions'] = 'Use NCALayer below to create a real CMS signature for this request.';
$string['ncarunninghint'] = 'NCALayer must be installed and running on this signer machine.';
$string['storage'] = 'Key storage';
$string['loadtokens'] = 'Load available tokens';
$string['signwithnca'] = 'Sign with NCALayer';
$string['signedok'] = 'Signature recorded successfully.';
$string['invalidtoken'] = 'Invalid or expired signing token.';
$string['alreadysigned'] = 'This request has already been signed.';
$string['signernotactive'] = 'This signing link is not active yet. A previous signer still needs to sign first.';
$string['invalidpayload'] = 'Invalid signing payload received.';
$string['emptysignature'] = 'Empty signature received from NCALayer.';
$string['nodocumentpayload'] = 'No document PDF is currently attached for signing.';
$string['verificationfailed'] = 'Signature verification failed: {$a}';
$string['verificationunavailable'] = 'Server-side signature verification is not available on this server.';
$string['signeriinmismatch'] = 'Signer IIN does not match the configured commission member.';
$string['draftpdf'] = 'Draft PDF';
$string['currentsignedpdf'] = 'Current signed PDF';
$string['signedpdfprogresslabel'] = 'Signed PDF progress';
$string['signedpdffinallabel'] = 'Final signed PDF';
$string['verifytitle'] = 'Document verification';
$string['verifyinvalidlink'] = 'Invalid verification link.';
$string['verifynotfound'] = 'The requested document could not be found.';
$string['verifyauthentic'] = 'AUTHENTIC';
$string['verifymodified'] = 'DOCUMENT MODIFIED';
$string['verifyunavailable'] = 'VERIFICATION UNAVAILABLE';
$string['verifydocumentinfo'] = 'Document info';
$string['verifyuserinfo'] = 'User info';
$string['verifysignatures'] = 'Signature block';
$string['verifyintegrity'] = 'Integrity status';
$string['verifydocumenttype'] = 'Document type';
$string['verifydocumenttitle'] = 'Document title';
$string['verifycoursename'] = 'Course name';
$string['verifyissuedate'] = 'Issue date';
$string['verifyorganisation'] = 'Issuing organisation';
$string['verifyfullname'] = 'Full name';
$string['verifycompletiondate'] = 'Completion date';
$string['verifyposition'] = 'Position';
$string['verifysignedat'] = 'Signed at';
$string['verifyhash'] = 'Stored SHA-256';
$string['verifycurrenthash'] = 'Current SHA-256';
$string['verifynosigners'] = 'No signer records found for this document.';
$string['verifypublicid'] = 'Public document ID';
