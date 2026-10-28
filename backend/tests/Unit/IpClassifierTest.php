<?php

namespace Tests\Unit;

use App\Support\Network\IpClassifier;
use PHPUnit\Framework\TestCase;

class IpClassifierTest extends TestCase
{
    public function test_matches_exact_ip_and_cidr(): void
    {
        $ranges = ['103.10.20.5', '192.168.1.0/24', '10.0.0.0/8'];

        $this->assertSame('OFFICE', IpClassifier::classify('103.10.20.5', $ranges));
        $this->assertSame('OFFICE', IpClassifier::classify('192.168.1.77', $ranges));
        $this->assertSame('OFFICE', IpClassifier::classify('10.44.1.9', $ranges));
        $this->assertSame('EXTERNAL', IpClassifier::classify('114.5.6.7', $ranges));
        $this->assertSame('EXTERNAL', IpClassifier::classify('192.168.2.10', $ranges));
    }

    public function test_unknown_when_no_ranges_or_bad_ip(): void
    {
        $this->assertSame('UNKNOWN', IpClassifier::classify('8.8.8.8', []));
        $this->assertSame('UNKNOWN', IpClassifier::classify('bukan-ip', ['10.0.0.0/8']));
        $this->assertSame('UNKNOWN', IpClassifier::classify(null, ['10.0.0.0/8']));
    }

    public function test_label(): void
    {
        $this->assertSame('Jaringan Kantor', IpClassifier::label('OFFICE'));
        $this->assertSame('Di Luar Kantor', IpClassifier::label('EXTERNAL'));
        $this->assertSame('Tidak Diketahui', IpClassifier::label('UNKNOWN'));
    }
}
