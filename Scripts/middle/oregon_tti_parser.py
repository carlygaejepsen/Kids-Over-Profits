import re
import json
from datetime import datetime
from typing import Dict, List, Optional, Tuple
from dataclasses import dataclass, asdict
from pathlib import Path

@dataclass
class FacilityInfo:
    licensee: str
    executive_director: str
    program_directors: List[str]
    visit_date: str
    licensing_coordinator: str
    other_agencies: str

@dataclass
class PreviousFinding:
    rule_citation: str
    rule_description: str
    violation_details: str
    is_repeat: bool
    resolution_status: str
    comments: str

@dataclass
class YouthConcern:
    category: str
    concern_text: str
    follow_up_action: Optional[str] = None

@dataclass
class InspectionReport:
    facility_info: FacilityInfo
    previous_findings: List[PreviousFinding]
    new_findings: str
    youth_concerns: List[YouthConcern]
    observations: str
    corrective_actions: str
    report_date: str
    
class OregonTTIParser:
    def __init__(self):
        self.current_report = None
        
    def parse_report(self, text_content: str) -> InspectionReport:
        """Parse a complete Oregon TTI inspection report"""
        
        # Extract main sections
        facility_info = self._extract_facility_info(text_content)
        previous_findings = self._extract_previous_findings(text_content)
        new_findings = self._extract_new_findings(text_content)
        youth_concerns = self._extract_youth_concerns(text_content)
        observations = self._extract_observations(text_content)
        corrective_actions = self._extract_corrective_actions(text_content)
        
        return InspectionReport(
            facility_info=facility_info,
            previous_findings=previous_findings,
            new_findings=new_findings,
            youth_concerns=youth_concerns,
            observations=observations,
            corrective_actions=corrective_actions,
            report_date=facility_info.visit_date
        )
    
    def _extract_facility_info(self, text: str) -> FacilityInfo:
        """Extract basic facility information from header"""
        
        # Extract licensee
        licensee_match = re.search(r'Licensee:\s*(.+?)(?:\n|Executive)', text)
        licensee = licensee_match.group(1).strip() if licensee_match else ""
        
        # Extract executive director
        exec_dir_match = re.search(r'Executive Director:\s*(.+?)(?:\n|Program)', text)
        exec_director = exec_dir_match.group(1).strip() if exec_dir_match else ""
        
        # Extract program directors (can be multiple)
        prog_dir_match = re.search(r'Program Director\(s\):\s*(.+?)(?:\n|Date)', text)
        if prog_dir_match:
            prog_dirs_text = prog_dir_match.group(1).strip()
            # Split by common separators
            prog_directors = [d.strip() for d in re.split(r'[,&]|and', prog_dirs_text) if d.strip()]
        else:
            prog_directors = []
        
        # Extract visit date
        date_match = re.search(r'Date of Unannounced:\s*(.+?)(?:\n|Licensing)', text)
        visit_date = date_match.group(1).strip() if date_match else ""
        
        # Extract licensing coordinator
        coord_match = re.search(r'Licensing Coordinator:\s*(.+?)(?:\n|Other)', text)
        coordinator = coord_match.group(1).strip() if coord_match else ""
        
        # Extract other agencies
        agencies_match = re.search(r'Other Regulatory or Accrediting Agencies:\s*(.+?)(?:\n|Purpose)', text)
        other_agencies = agencies_match.group(1).strip() if agencies_match else ""
        
        return FacilityInfo(
            licensee=licensee,
            executive_director=exec_director,
            program_directors=prog_directors,
            visit_date=visit_date,
            licensing_coordinator=coordinator,
            other_agencies=other_agencies
        )
    
    def _extract_previous_findings(self, text: str) -> List[PreviousFinding]:
        """Extract previous findings table"""
        findings = []
        
        # Find the Previous Findings section
        prev_section_match = re.search(
            r'Previous Findings.*?(?=New Findings from Site Visit)', 
            text, 
            re.DOTALL
        )
        
        if not prev_section_match:
            return findings
            
        prev_section = prev_section_match.group(0)
        
        # Look for rule citations and their details
        # Pattern: Rule citation followed by description and details
        rule_patterns = [
            r'([0-9-]+(?:\([^)]+\))*)\s*([^•]+?)(?=•|Yes☐|$)',
            r'(\w+.*?[0-9-]+(?:\([^)]+\))*)\s*([^•]+?)(?=•|Yes☐|$)'
        ]
        
        # Split by entries that start with rule citations or keywords
        entries = re.split(r'(?=\w+.*?[0-9-]+)', prev_section)
        
        for entry in entries[1:]:  # Skip first empty split
            if not entry.strip():
                continue
                
            finding = self._parse_finding_entry(entry)
            if finding:
                findings.append(finding)
        
        return findings
    
    def _parse_finding_entry(self, entry: str) -> Optional[PreviousFinding]:
        """Parse individual finding entry"""
        
        # Extract rule citation (first line usually)
        rule_match = re.search(r'([A-Za-z\s]*[0-9-]+(?:\([^)]+\))*)', entry)
        rule_citation = rule_match.group(1).strip() if rule_match else ""
        
        # Extract rule description (text after citation, before bullet points)
        desc_match = re.search(r'[0-9-]+(?:\([^)]+\))*(.*?)(?=•|Yes☐)', entry, re.DOTALL)
        rule_description = desc_match.group(1).strip() if desc_match else ""
        
        # Extract violation details (bullet points)
        violation_details = []
        bullet_matches = re.findall(r'•\s*([^•]+?)(?=•|Yes☐|$)', entry, re.DOTALL)
        for match in bullet_matches:
            violation_details.append(match.strip())
        
        # Check for repeat finding
        repeat_match = re.search(r'Yes☑|Yes✓', entry)
        is_repeat = repeat_match is not None
        
        # Extract resolution status
        no_match = re.search(r'No☑|No✓', entry)
        resolution_status = "Resolved" if no_match else "Pending"
        
        # Extract comments section
        comments_match = re.search(r'No[☑✓]\s*(.+?)(?=\n\n|\n[A-Z]|$)', entry, re.DOTALL)
        comments = comments_match.group(1).strip() if comments_match else ""
        
        if rule_citation or rule_description:
            return PreviousFinding(
                rule_citation=rule_citation,
                rule_description=rule_description,
                violation_details="; ".join(violation_details),
                is_repeat=is_repeat,
                resolution_status=resolution_status,
                comments=comments
            )
        
        return None
    
    def _extract_new_findings(self, text: str) -> str:
        """Extract new findings section"""
        new_match = re.search(
            r'New Findings from Site Visit.*?Comments\s*\n\s*(.+?)(?=Interview Summary)', 
            text, 
            re.DOTALL
        )
        
        if new_match:
            findings = new_match.group(1).strip()
            return findings if findings != "N/A" else "No new findings"
        
        return "No new findings section found"
    
    def _extract_youth_concerns(self, text: str) -> List[YouthConcern]:
        """Extract youth interview concerns and follow-ups"""
        concerns = []
        
        # Find Interview Summary section
        interview_match = re.search(
            r'Interview Summary.*?(?=Observations)', 
            text, 
            re.DOTALL
        )
        
        if not interview_match:
            return concerns
            
        interview_section = interview_match.group(0)
        
        # Categories of concerns to look for
        concern_categories = {
            'food': ['food', 'meal', 'chef', 'eat', 'hungry', 'frozen'],
            'safety': ['safe', 'uncomfortable', 'panic attack', 'hurt'],
            'staff_conduct': ['body comments', 'unprofessional', 'disrespectful', 'training'],
            'hygiene': ['hygiene', 'toothpaste', 'products', 'clean'],
            'operations': ['late', 'routine', 'consistent', 'understaffed']
        }
        
        # Find follow-up actions
        follow_ups = re.findall(
            r'Follow Up:.*?(?=\n\s*•|\n\n|$)', 
            interview_section, 
            re.DOTALL
        )
        
        for follow_up in follow_ups:
            # Extract the concern from context before the follow-up
            concern_text = self._extract_concern_context(interview_section, follow_up)
            category = self._categorize_concern(concern_text, concern_categories)
            
            concerns.append(YouthConcern(
                category=category,
                concern_text=concern_text,
                follow_up_action=follow_up.strip()
            ))
        
        return concerns
    
    def _extract_concern_context(self, interview_text: str, follow_up: str) -> str:
        """Extract the concern text that led to a follow-up"""
        # Find the bullet point that contains this follow-up
        follow_up_start = interview_text.find(follow_up)
        if follow_up_start == -1:
            return ""
            
        # Look backwards for the start of this bullet point
        bullet_start = interview_text.rfind('•', 0, follow_up_start)
        if bullet_start == -1:
            return ""
            
        # Extract text from bullet to follow-up
        concern_section = interview_text[bullet_start:follow_up_start]
        
        # Clean up and extract the actual concern
        concern_match = re.search(r'•\s*(.+?)(?=Follow Up)', concern_section, re.DOTALL)
        if concern_match:
            return concern_match.group(1).strip()
        
        return concern_section.strip()
    
    def _categorize_concern(self, concern_text: str, categories: Dict[str, List[str]]) -> str:
        """Categorize a concern based on keywords"""
        concern_lower = concern_text.lower()
        
        for category, keywords in categories.items():
            if any(keyword in concern_lower for keyword in keywords):
                return category
        
        return 'other'
    
    def _extract_observations(self, text: str) -> str:
        """Extract observations section"""
        obs_match = re.search(
            r'Observations\s*\n\s*(.+?)(?=Corrective Actions)', 
            text, 
            re.DOTALL
        )
        
        return obs_match.group(1).strip() if obs_match else ""
    
    def _extract_corrective_actions(self, text: str) -> str:
        """Extract corrective actions section"""
        ca_match = re.search(
            r'Corrective Actions and Timeframes:\s*(.+?)(?=Licensing Coordinator)', 
            text, 
            re.DOTALL
        )
        
        return ca_match.group(1).strip() if ca_match else ""
    
    def parse_file(self, file_path: str) -> InspectionReport:
        """Parse a file and return structured data"""
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        return self.parse_report(content)
    
    def to_json(self, report: InspectionReport) -> str:
        """Convert report to JSON"""
        return json.dumps(asdict(report), indent=2, default=str)

# Example usage
def main():
    parser = OregonTTIParser()
    
    # Parse a report file
    # report = parser.parse_file('report.txt')
    
    # Or parse text content directly
    sample_text = """Your report text here"""
    # report = parser.parse_report(sample_text)
    
    # Convert to JSON
     json_output = parser.to_json(report)
     print(json_output)

if __name__ == "__main__":
    main()