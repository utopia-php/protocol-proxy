<?php

namespace Utopia\Proxy;

enum Protocol: string
{
    case HTTP = 'http';
    case SMTP = 'smtp';
    case TCP = 'tcp';
    case PostgreSQL = 'postgresql';
    case MySQL = 'mysql';
    case MongoDB = 'mongodb';
}
