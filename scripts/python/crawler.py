#!/usr/bin/env python3
"""
Web Crawler - Extracts domains and IPs from websites
Usage: python crawler.py <url> [--depth N] [--verbose]
"""

import argparse
import re
import socket
import json
from urllib.parse import urljoin, urlparse
from pathlib import Path
import requests
from bs4 import BeautifulSoup
from collections import deque
import time
import sqlite3


SCRIPT_DIR = Path(__file__).parent
DB_PATH = SCRIPT_DIR / '../../backend/laravel/database/database.sqlite'


def is_valid_ipv4(ip: str) -> bool:
    try:
        socket.inet_pton(socket.AF_INET, ip)
        return True
    except socket.error:
        return False


def is_valid_ipv6(ip: str) -> bool:
    try:
        socket.inet_pton(socket.AF_INET6, ip)
        return True
    except socket.error:
        return False


def format_ipv6(ip: str) -> str:
    if is_valid_ipv6(ip):
        return f"[{ip}]"
    return ip


class WebCrawler:
    def __init__(self, start_url: str, max_depth: int = 2, verbose: bool = False):
        self.start_url = start_url
        self.max_depth = max_depth
        self.verbose = verbose
        self.visited = set()
        self.saved_items = set()
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        })

    def log(self, msg: str):
        if self.verbose:
            print(f"[CRAWLER] {msg}")

    def extract_hosts_from_links(self, html: str, base_url: str) -> set:
        found = set()
        
        soup = BeautifulSoup(html, 'html.parser')
        
        for tag in soup.find_all(['a', 'link', 'script', 'img']):
            for attr in ['href', 'src']:
                url = tag.get(attr)
                if not url:
                    continue
                
                full_url = urljoin(base_url, url)
                parsed = urlparse(full_url)
                
                if parsed.netloc:
                    found.add(parsed.netloc)
        
        return found

    def extract_ips_from_text(self, html: str) -> set:
        found = set()
        
        text = BeautifulSoup(html, 'html.parser').get_text()
        
        ipv4_pattern = r'\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b'
        for match in re.finditer(ipv4_pattern, text):
            ip = match.group()
            if is_valid_ipv4(ip) and ip not in ['127.0.0.1', '0.0.0.0', '255.255.255.255', '255.255.255.0']:
                found.add(ip)
        
        ipv6_patterns = [
            r'\b([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}\b',
            r'\b([0-9a-fA-F]{1,4}:){1,7}:',
            r'\b([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}\b',
            r'\b::([0-9a-fA-F]{1,4}:){0,6}[0-9a-fA-F]{1,4}\b',
            r'\b::([0-9a-fA-F]{1,4}:){0,5}[0-9a-fA-F]{1,4}\b',
            r'\b([0-9a-fA-F]{1,4}:){1,5}:([0-9a-fA-F]{1,4})',
        ]
        
        for pattern in ipv6_patterns:
            for match in re.finditer(pattern, text):
                ip = match.group()
                if is_valid_ipv6(ip):
                    found.add(ip)
        
        return found

    def get_db_connection(self):
        conn = sqlite3.connect(str(DB_PATH))
        conn.row_factory = sqlite3.Row
        return conn

    def save_link(self, name: str):
        conn = self.get_db_connection()
        
        existing = conn.execute(
            'SELECT id FROM links WHERE name = ?',
            (name,)
        ).fetchone()
        
        if not existing:
            formatted = format_ipv6(name) if is_valid_ipv6(name) else name
            conn.execute(
                'INSERT INTO links (name, urls) VALUES (?, ?)',
                (name, json.dumps([formatted]))
            )
        
        conn.commit()
        conn.close()

    def crawl(self):
        queue = deque([(self.start_url, 0)])
        
        base_domain = urlparse(self.start_url).netloc
        
        while queue:
            url, depth = queue.popleft()
            
            if url in self.visited or depth > self.max_depth:
                continue
            
            self.visited.add(url)
            self.log(f"Crawling ({depth}/{self.max_depth}): {url}")
            
            try:
                response = self.session.get(url, timeout=10)
                response.raise_for_status()
                
                hosts = self.extract_hosts_from_links(response.text, url)
                external_hosts = [h for h in hosts if h != base_domain]
                
                ips = self.extract_ips_from_text(response.text)
                
                all_found = set(external_hosts) | set(ips)
                
                for item in all_found:
                    if item not in self.saved_items:
                        self.save_link(item)
                        self.saved_items.add(item)
                
                for host in external_hosts:
                    if host not in self.visited:
                        try:
                            full_url = f"http://{host}"
                            queue.append((full_url, depth + 1))
                        except Exception:
                            pass
                
                time.sleep(0.2)
                
            except requests.RequestException as e:
                self.log(f"Error crawling {url}: {e}")

    def print_summary(self):
        print(f"\n{'='*50}")
        print(f"Crawl Summary")
        print(f"{'='*50}")
        print(f"Start URL: {self.start_url}")
        print(f"Max Depth: {self.max_depth}")
        print(f"Pages Visited: {len(self.visited)}")
        print(f"Unique Domains/IPs: {len(self.saved_items)}")
        print()
        
        conn = self.get_db_connection()
        total = conn.execute('SELECT COUNT(*) FROM links').fetchone()[0]
        print(f"Total links in database: {total}")
        
        print("\nFound:")
        for item in sorted(self.saved_items)[:30]:
            print(f"  - {item}")
        
        if len(self.saved_items) > 30:
            print(f"  ... and {len(self.saved_items) - 30} more")
        
        conn.close()


def main():
    parser = argparse.ArgumentParser(description='Web Crawler - Extracts domains and IPs')
    parser.add_argument('url', help='Starting URL')
    parser.add_argument('--depth', '-d', type=int, default=2, help='Crawl depth (default: 2)')
    parser.add_argument('--verbose', '-v', action='store_true', help='Verbose output')
    
    args = parser.parse_args()
    
    if not args.url:
        parser.error("URL is required")
    
    crawler = WebCrawler(args.url, args.depth, args.verbose)
    crawler.crawl()
    crawler.print_summary()


if __name__ == '__main__':
    main()