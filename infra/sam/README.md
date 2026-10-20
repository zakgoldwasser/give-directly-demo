# Demo cloud resources

This SAM stack creates only the AWS resources needed by the Vercel-hosted demo:

- one private, SSE-S3 encrypted bucket;
- a two-day lifecycle safety net under `reconciliation/`;
- public-access and insecure-transport blocks; and
- one IAM user limited to the application's import and export prefixes.

Neon is managed separately and does not require an AWS or SAM resource.

## Deploy the stack

Install and authenticate the AWS CLI and AWS SAM CLI, then run from the repository root:

```powershell
sam validate --lint --template-file infra/sam/template.yaml
sam deploy --guided --template-file infra/sam/template.yaml --stack-name give-directly-demo-storage --capabilities CAPABILITY_IAM
```

Choose the AWS region nearest the Vercel and Neon regions. Leave `BucketName` blank unless a stable, globally unique name is required. The bucket and its contents are retained if the stack is deleted so an accidental stack deletion does not destroy active demo artifacts.

Read the deployed values with:

```powershell
aws cloudformation describe-stacks --stack-name give-directly-demo-storage --query "Stacks[0].Outputs" --output table
```

The template deliberately does not create an `AWS::IAM::AccessKey`: CloudFormation would retain that long-lived credential in stack state. In IAM, open the user named by the `StorageUserName` output, create one access key, and save it directly as `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` in Vercel. Do not save it in this repository.

Set these Vercel variables from the stack outputs:

```dotenv
RECONCILIATION_STORAGE_DRIVER=s3
AWS_ACCESS_KEY_ID=<IAM access key ID>
AWS_SECRET_ACCESS_KEY=<IAM secret access key>
AWS_DEFAULT_REGION=<BucketRegion output>
AWS_BUCKET=<BucketName output>
AWS_USE_PATH_STYLE_ENDPOINT=false
RECONCILIATION_IMPORTS_ROOT=reconciliation/imports
RECONCILIATION_EXPORTS_ROOT=reconciliation/exports
```

Leave `AWS_ENDPOINT` unset for Amazon S3.

## Connect Neon

Create a Neon project in a region near the Vercel deployment and copy its **pooled** connection string. Laravel already accepts a PostgreSQL URL, so no application adapter or AWS resource is required. Set:

```dotenv
DB_CONNECTION=pgsql
DB_URL=postgresql://<user>:<password>@<pooled-host>/<database>?sslmode=require
DB_SSLMODE=require
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
```

Keep `DB_SSLMODE=require` even though the URL contains the same option; the deployment preflight checks the explicit Laravel setting. Treat `DB_URL` as a secret. Run the migrations once against Neon before deployment:

```powershell
php artisan migrate --force
php artisan reconciliation:verify-deployment
```

The production container includes PostgreSQL support. The pooled Neon endpoint is appropriate for Vercel's request-driven containers and avoids consuming a direct PostgreSQL connection per container.
