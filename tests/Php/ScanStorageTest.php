<?php

declare(strict_types=1);

namespace FenPing\Tests;

final class ScanStorageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testStructuredSnapshotRoundTripAndChangeDetection(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<nmaprun scanner="nmap" version="7.98" args="nmap -A -p- 192.0.2.10" startstr="test start">
  <scaninfo type="syn" protocol="tcp" services="1-65535"/>
  <host>
    <status state="up" reason="arp-response" reason_ttl="64"/>
    <address addr="192.0.2.10" addrtype="ipv4"/>
    <address addr="00:11:22:33:44:55" addrtype="mac" vendor="Example Vendor"/>
    <hostnames><hostname name="test-host" type="PTR"/></hostnames>
    <ports>
      <extraports state="closed" count="65534"><extrareasons reason="resets" count="65534" proto="tcp" ports="1-21,23-65535"/></extraports>
      <port protocol="tcp" portid="22">
        <state state="open" reason="syn-ack" reason_ttl="64"/>
        <service name="ssh" product="OpenSSH" version="9.9" extrainfo="protocol 2.0" method="probed" conf="10"><cpe>cpe:/a:openbsd:openssh:9.9</cpe></service>
        <script id="ssh-hostkey" output="key output"><table key="rsa"><elem key="bits">4096</elem></table></script>
      </port>
    </ports>
    <os><osmatch name="Linux" accuracy="98"><osclass vendor="Linux" osfamily="Linux" osgen="6.X" type="general purpose" accuracy="98"><cpe>cpe:/o:linux:linux_kernel:6</cpe></osclass></osmatch></os>
    <uptime seconds="3600" lastboot="2026-07-11 12:00:00"/>
    <distance value="1"/>
    <hostscript><script id="uptime" output="one hour"><elem key="seconds">3600</elem></script></hostscript>
    <trace proto="tcp" port="80"><hop ttl="1" ipaddr="192.0.2.1" host="router" rtt="0.250"/></trace>
  </host>
  <runstats><finished elapsed="12.4" exit="success"/></runstats>
</nmaprun>
XML;
        $codec = $this->app()->scanXml();
        $scan = $codec->parse($xml, ['ip' => '192.0.2.10']);
        self::assertSame('OpenSSH', $scan['ports'][0]['product']);
        self::assertSame('4096', $scan['ports'][0]['scripts'][0]['nodes'][1]['value']);
        self::assertSame('192.0.2.1', $scan['trace'][0]['ip']);
        self::assertCount(2, $codec->parseScriptNodes('<script id="test"><elem key="empty"/><elem key="next">value</elem></script>'));

        $firstId = $this->app()->scanJobs()->start('192.0.2.10', 'deep');
        self::assertTrue($this->app()->scanJobs()->complete($firstId, $scan));
        $metadata = $this->app()->scanJobs()->byId('192.0.2.10', $firstId);
        $stored = $this->app()->snapshots()->read('192.0.2.10', $metadata);
        self::assertSame('OpenSSH 9.9 protocol 2.0', $stored['ports'][0]['details']);

        $roundTrip = $codec->parse($codec->render($stored), ['ip' => '192.0.2.10']);
        self::assertSame($codec->semanticHash($scan), $codec->semanticHash($roundTrip));

        $scriptOnly = $scan;
        $scriptOnly['scripts'][0]['output'] = 'updated output';
        $secondId = $this->app()->scanJobs()->start('192.0.2.10', 'deep');
        self::assertFalse($this->app()->scanJobs()->complete($secondId, $scriptOnly));

        $serviceChange = $scriptOnly;
        $serviceChange['ports'][0]['version'] = '10.0';
        $serviceChange['ports'][0]['details'] = 'OpenSSH 10.0 protocol 2.0';
        $thirdId = $this->app()->scanJobs()->start('192.0.2.10', 'deep');
        self::assertTrue($this->app()->scanJobs()->complete($thirdId, $serviceChange));
        self::assertSame(1, (int) $this->app()->database()->connection()->query("SELECT COUNT(*) FROM scan_port_changes WHERE change_type='changed'")->fetchColumn());
    }
}
