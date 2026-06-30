# Github playground url repo

## Status - in progress
## Description

### Purpose
Allows a long URL to be saved inside a github repo with a uuid. Basically a cheap and free way to shorten urls.

### Implementation
Require github functions (within github service) with authentication to github api.

For each URL that we want to shorten we should populate urlFile.twig with the url.
The rendered template should then be committed to github with the appropriate uuid as the filename - e.g. ADGH43284328.html

The repository will be managed via github API.
We will need to ensure the globalConfig contains githubToken. If not it should prevent and warning when running the playground command.

We will also need the repository to be in the globalConfig $githubUrlsRepo. If not present, should warn when running playground command.

We need a Github service method that uses this rough php code to pubish to the repository, it should return the URL to the github resource we have created:

$repo = 'citricity/mchef-urls';
$path; // this should be an argument of the method.

$payload = [
    'message' => "Add URL redirect $id",
    'content' => base64_encode($html),
    'branch' => 'main',
];

$ch = curl_init("https://api.github.com/repos/$repo/contents/$path");
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: mchef',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

Please create unit tests for the github service.
The templating code should be done inside the QrService - also create unit tests for this.